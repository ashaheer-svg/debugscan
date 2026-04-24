# DSM 7.xx Extraction Fixes Required

## Executive Summary

DSM 7.xx bundles have a **different JSON structure** than DSM 6. The current extraction code works for some fields but fails for others:

### Current Status
✅ **Working:**
- Basic device info (model, serial, CPU cores)
- Drive model, serial, capacity, temperature
- RAID array configuration and status
- Volume information

❌ **Broken:**
- **Expansion unit detection** (CRITICAL)
- **Drive location classification** (CRITICAL)
- **Power-on hours** (needs fallback handling)

---

## Critical Issues

### 1. EXPANSION UNITS NOT DETECTED (CRITICAL)
**Problem:** No "Expansion Units" section in report despite system having 2 expansion units

**Why it fails:**
- `extractEnclosureData()` looks for `$loadInfo['enclosures']` array
- DSM 7.xx doesn't have this array
- Fallback logic uses device naming pattern (`sde[a-z]`)
- New DSM naming: `sdma, sdmb, sdnc...` (not `sdea, sdeb, sdec`)
- Pattern never matches → no expansion units detected

**System config (from bundle):**
```
Main unit: RS3617RPxs - 12 drives (sda-sdl)
Expansion 1: RX1217rp-1 - 12 drives (sdma-sdnc) 
Expansion 2: RX1217rp-2 - 12 drives (sdoa-sdtc)
Total: 36 drives
```

**Report shows:** 36 drives ✓ but all labeled "Main" ✗

**Root Cause:** Code doesn't use `container.type == "ebox"` which is the reliable way to detect expansion units in DSM 7.xx

### 2. DRIVE LOCATION WRONG (CRITICAL)
**Problem:** All 36 drives show location "Main" instead of proper unit names

**Why it fails:**
- Drives are extracted correctly
- Location comes from drive location field which defaults to "Main"
- Extraction code doesn't use the `container.str` field which has the actual unit name

**Should be:**
- Drives 1-12 (sda-sdl): Location = "Main"
- Drives 13-24 (sdma-sdnc): Location = "RX1217rp-1"
- Drives 25-36 (sdoa-sdtc): Location = "RX1217rp-2"

### 3. POWER-ON HOURS SHOWS 0h (MEDIUM)
**Problem:** All drives show "0h" which is misleading

**Why it happens:**
- DSM 7.xx `load_info.result` has `power_on_hours: null`
- Code converts null to `(int)null` = 0
- Displays as "0h" instead of indicating "data not available"

**Impact:** Misleading users into thinking drives have zero usage

---

## Detailed Fix Requirements

### Fix 1: Update extractEnclosureData() - Lines 915-1022

**Current approach (fails for DSM 7.xx):**
1. Look for `$loadInfo['enclosures']` ← doesn't exist
2. Fallback to device regex `sde[a-z]` ← wrong pattern

**New approach (DSM 7.xx native):**
1. Group all drives by `container.str` and `container.type`
2. For each container with `type == "ebox"`:
   - Create expansion unit entry
   - Use container.str as model name
   - List all drives in that container

**Implementation:**
```php
private function extractEnclosureData(array $loadInfo, array $disksArray): array
{
    $units = [];
    $containerDrives = [];
    
    // Group drives by container
    foreach ($disksArray as $disk) {
        if (!is_array($disk)) continue;
        
        $container = $disk['container']['str'] ?? null;
        $type = $disk['container']['type'] ?? null;
        
        if ($container && $type) {
            if (!isset($containerDrives[$container])) {
                $containerDrives[$container] = [
                    'type' => $type,
                    'order' => $disk['container']['order'] ?? 0,
                    'drives' => []
                ];
            }
            $containerDrives[$container]['drives'][] = $disk['id'] ?? '';
        }
    }
    
    // Create expansion units (skip internal)
    foreach ($containerDrives as $name => $data) {
        if ($data['type'] === 'ebox') {  // Only expansion boxes
            $units[] = [
                'enclosure_id' => $name,
                'model' => $name,  // e.g., "RX1217rp-1"
                'serial' => '',
                'bay_count' => count($data['drives']),
                'installed_drives' => count($data['drives']),
                'drives' => $data['drives'],
                'status' => 'active',
                'power_status' => 'online'
            ];
        }
    }
    
    return $units;
}
```

### Fix 2: Update extractDrives() - Drive location field

**Current code (approximately):**
```php
$location = htmlspecialchars((string)($drive['location'] ?? 'main'), ENT_QUOTES);
```

**Updated code:**
```php
// Use container information for accurate location
$containerType = $drive['container']['type'] ?? '';
$containerName = $drive['container']['str'] ?? '';

if ($containerType === 'ebox') {
    $location = htmlspecialchars($containerName, ENT_QUOTES);  // "RX1217rp-1"
} else if ($containerType === 'internal' || empty($containerType)) {
    $location = 'Main';
} else {
    $location = htmlspecialchars($containerName, ENT_QUOTES);
}
```

### Fix 3: Handle Missing Power-On Hours - extractDrives()

**Current code (produces misleading "0h"):**
```php
$poh = (int)($drive['power_on_hours'] ?? 0);
```

**Updated code:**
```php
// DSM 7.xx doesn't include power_on_hours in load_info
// Don't show 0h as it's misleading - indicate unavailable instead
$poh = null;
if (isset($drive['power_on_hours']) && $drive['power_on_hours'] !== null) {
    $poh = (int)$drive['power_on_hours'];
}
// In template/rendering: show "N/A" if $poh is null
```

**In report rendering (ReportRenderer.php):**
```php
$pohDisplay = ($poh === null || $poh < 0) ? 'N/A' : ($poh . 'h');
// Then use $pohDisplay instead of $poh in HTML
```

---

## Testing Checklist

After making fixes, verify:

- [ ] Expansion Units section appears in Hardware Configuration
- [ ] Shows 2 expansion units: RX1217rp-1 and RX1217rp-2
- [ ] Each expansion unit shows 12 installed drives
- [ ] Main unit shows 12 drives
- [ ] Drives table shows correct locations:
  - [ ] Drives 1-12: Location = "Main"
  - [ ] Drives 13-24: Location = "RX1217rp-1"
  - [ ] Drives 25-36: Location = "RX1217rp-2"
- [ ] Power-on hours shows "N/A" instead of "0h"
- [ ] All other drive data intact (model, serial, capacity, temp, status)
- [ ] DSM 6 bundles still work correctly (backward compatibility)

---

## Impact

**Before fix:**
- Report shows incomplete hardware picture
- Users can't identify which expansion unit drives belong to
- Misleading 0h power-on hours
- Missing expansion unit details and status

**After fix:**
- ✅ Complete hardware inventory
- ✅ Proper drive organization by unit
- ✅ Accurate power usage indicator (N/A when unavailable)
- ✅ Full expansion unit information
- ✅ Better diagnostic capability

---

## File Locations

Key files to modify:
1. `src/DeepDive/Hardware/HardwareSpecExtractor.php`
   - Lines 915-1022: `extractEnclosureData()`
   - `extractDrives()` method: location and POH fields
   
2. `src/DeepDive/Report/ReportRenderer.php` (optional)
   - Display logic for N/A power-on hours

---

## Related Documentation

- See `EXPANSION_UNIT_DETECTION_ISSUE.md` for detailed technical breakdown
- See `DRIVE_ANALYSIS_ISSUES.md` for drive extraction analysis
- DSM 7.xx bundle: `/sample/debug.dat.dat` (for testing)
