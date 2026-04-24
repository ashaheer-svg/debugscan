# DSM 7.xx Expansion Unit Detection Issue

## Problem Summary
Expansion units are **NOT being detected** in DSM 7.xx systems, causing:
- Missing "Expansion Units" table in report
- All drives labeled as "Main" location instead of proper expansion unit grouping  
- Incomplete hardware inventory (36 drives exist but only show location "Main")

## Root Cause Analysis

### Issue 1: DSM 7.xx JSON Structure Difference
**DSM 6 Format:**
- Separate `load_info.result['enclosures']` array for expansion units
- Requires parsing external enclosure metadata

**DSM 7.xx Format:**
- **No separate enclosures array** - expansion units are embedded in the disks array
- Each disk has a `container` object with:
  - `container.type`: "internal" (main unit) or "ebox" (expansion box)
  - `container.str`: Expansion unit model (e.g., "RX1217rp-1", "RX1217rp-2")
  - `container.order`: Order number of expansion unit

### Issue 2: Device Naming Pattern Changed
**Current detection logic (line 994):**
```php
if (preg_match('/^sde[a-z]/', $device)) {  // Looks for sdea, sdeb, sdec...
    if (!isset($expansionDrives[$container])) {
        $expansionDrives[$container] = [];
    }
    $expansionDrives[$container][] = $device;
}
```

**Problem:** 
- Old expansion devices: sdea, sdeb, sdec, ... (predictable `sde` prefix)
- New expansion devices: sdma, sdmb, sdmc, ..., sdna, sdnb, ..., sdoa, sdob, ..., etc.
- The regex only matches `sde[a-z]` pattern, missing all newer multi-letter device names

### Issue 3: Unreliable Fallback
Instead of parsing the actual container metadata that DSM 7.xx provides, the code relies on:
1. Looking for `load_info['enclosures']` (doesn't exist in DSM 7.xx)
2. Fallback to device naming pattern (which changed in newer systems)
3. Never actually uses the reliable `container.type == "ebox"` indicator

## Evidence from Bundle

**Actual DSM 7.xx structure:**
```json
{
  "data": {
    "disks": [
      {
        "id": "sda",
        "container": {"type": "internal", "str": "RS3617RPxs", "order": 0},
        "disk_location": "Main"
      },
      {
        "id": "sdma",
        "container": {"type": "ebox", "str": "RX1217rp-1", "order": 1},
        "disk_location": "Main"  // <-- WRONG, should be "RX1217rp-1"
      }
    ]
  }
}
```

**Actual system configuration:**
- Main unit (RS3617RPxs): 12 drives [sda-sdl]
- Expansion 1 (RX1217rp-1): 12 drives [sdma-sdnc]
- Expansion 2 (RX1217rp-2): 12 drives [sdoa-sdtc]
- **Total: 36 drives** ✓ (This is correct in the report)

## Current Report Output
- Shows all 36 drives
- ALL marked as location "Main" (WRONG)
- NO "Expansion Units" section (MISSING)
- Data exists but is grouped incorrectly

## Required Fixes

### Fix 1: Update extractEnclosureData() for DSM 7.xx
**Location:** HardwareSpecExtractor.php lines 915-1022

**Change required:**
- Instead of looking for `$loadInfo['enclosures']`, directly parse containers from disks array
- Group disks by `container.type` and `container.str`
- Create expansion unit entries for all containers with `type == "ebox"`
- Use `container.str` as the model name for expansion units
- Set accurate drive counts and lists per container

**Pseudocode:**
```php
private function extractEnclosureData(array $loadInfo, array $disksArray): array {
    $units = [];
    $containerDrives = []; // Group drives by container
    
    // Parse all disks and group by container
    foreach ($disksArray as $disk) {
        $container = $disk['container']['str'] ?? null;
        $containerType = $disk['container']['type'] ?? null;
        
        if ($container && $containerType) {
            if (!isset($containerDrives[$container])) {
                $containerDrives[$container] = [
                    'type' => $containerType,
                    'order' => $disk['container']['order'] ?? 0,
                    'drives' => []
                ];
            }
            $containerDrives[$container]['drives'][] = $disk['id'] ?? $disk['device'] ?? '';
        }
    }
    
    // Create expansion unit entries (skip internal/main unit)
    foreach ($containerDrives as $containerName => $data) {
        if ($data['type'] === 'ebox') {  // Only expansion boxes
            $units[] = [
                'enclosure_id' => $containerName,
                'model' => $containerName,  // e.g., "RX1217rp-1"
                'serial' => '',  // Extract if available in disk data
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

### Fix 2: Update extractDrives() to Use Proper Location
**Location:** HardwareSpecExtractor.php - extractDrives() method

**Change required:**
- When processing each drive, check `container.type` and `container.str`
- Set location to "main" for `type == "internal"`
- Set location to the expansion unit name for `type == "ebox"`
- This ensures drives show correct location in the Drives table

### Fix 3: Remove Device Naming Dependency
- Remove the fallback regex that depends on device naming patterns
- Rely instead on explicit `container.type` field which is guaranteed in DSM 7.xx
- This makes the code future-proof against naming scheme changes

## Impact
Once fixed:
- ✅ Expansion units (RX1217rp-1, RX1217rp-2) will be detected
- ✅ "Expansion Units" table will appear in Hardware Configuration
- ✅ Drives will show correct location (Main vs. Expansion unit name)
- ✅ Drive bay counts will be accurate (12 main + 12 per expansion)
- ✅ Drive replacement tracking will work correctly across units
- ✅ Full DSM 6/7 compatibility maintained (code handles both formats)

## Testing
To verify the fix:
1. Extract debug.dat.dat
2. Run HardwareSpecExtractor on the extracted path
3. Verify `$spec->expansion` contains 2 expansion unit entries
4. Verify each expansion unit lists 12 drives
5. Verify `$spec->drives` shows correct locations per drive
6. Verify report shows "Expansion Units" section with RX1217rp models
