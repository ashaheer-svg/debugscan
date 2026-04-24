# Drive Analysis Issues in DSM 7.xx Report

## Issues Found

### Issue 1: Power-On Hours (POH) Shows 0h for All Drives
**Current Report:** All 36 drives show "0h" for Hours column
**Expected:** Actual power-on hours from drives or SMART data
**Root Cause:** DSM 7.xx `load_info.result` doesn't include `power_on_hours` field

**DSM 7.xx Data Structure:**
```json
{
  "device": "/dev/sda",
  "power_on_hours": null,    // <-- NULL in DSM 7.xx
  "erase_time": 1548,         // <-- Different field, not POH
  "remain_life": {
    "trustable": true,
    "value": -1  // -1 = unlimited/not applicable
  }
}
```

**Fix Required:**
- Check if `power_on_hours` exists in DSM 7.xx at all
- If not available, check alternative fields (SMART data, elsewhere in load_info)
- Display "N/A" or dash instead of "0h" when data is unavailable
- Never show "0h" - this is misleading (implies the drive has zero usage)

### Issue 2: Drive Location All Marked as "Main"
**Current Report:** All 36 drives show location "Main"
**Expected:** Drives in expansion units should show "RX1217rp-1", "RX1217rp-2", etc.
**Root Cause:** This is the same issue as the missing expansion units (see EXPANSION_UNIT_DETECTION_ISSUE.md)

**Evidence:**
- Drive bay 13-24 (sdma-sdnc): Should be "RX1217rp-1" not "Main"
- Drive bay 25-36 (sdoa-sdtc): Should be "RX1217rp-2" not "Main"

The `extractDrives()` method needs to use `container.type` and `container.str` to properly classify drive locations.

### Issue 3: Capacity Column Shows Uniform Values
**Current Report:** All 36 drives show "4000.8 GB"
**Reality:** This appears correct based on the models present
**Status:** ✅ No issue - capacity extraction is working

### Issue 4: Temperature Readings Present but Wide Range
**Current Report:** 26°C to 43°C across drives
**Assessment:** This is realistic and suggests:
- Drives in different physical locations (main vs expansion units)
- Different ambient temperatures or airflow
- Some drives under more load than others
**Status:** ✅ No issue - temperature data is being extracted correctly

### Issue 5: Model/Serial Consistency
**Finding:** 
- Multiple different drive models present (ST4000NM*, HAT5300-4T, etc.)
- Serials are properly extracted and unique
**Status:** ✅ No issue - model and serial extraction working correctly

## Summary of Drive Extraction Issues

| Issue | Severity | Impact | Status |
|-------|----------|--------|--------|
| POH shows 0h instead of N/A | HIGH | Misleading data | Needs fix |
| All drives show "Main" location | CRITICAL | Wrong grouping | Needs fix (tied to expansion unit detection) |
| Capacity extraction | ✅ OK | N/A | Working |
| Temperature extraction | ✅ OK | N/A | Working |
| Model/Serial extraction | ✅ OK | N/A | Working |

## Data Completeness (77%)

The 77% completeness score likely reflects:
- ✅ Drive hardware: Extracted (model, serial, capacity, temp, status)
- ✅ SMART status: Extracted (smart_status field available)
- ❌ Power-on hours: Missing (not in DSM 7.xx load_info)
- ❌ Expansion unit metadata: Missing (not properly parsed)
- ❌ Drive bay assignment to expansion units: Missing (location not determined)
- ❌ Detailed SMART data: Not extracted (would require separate SMART data files)

The 23% gap is primarily due to:
1. Unavailable POH data in DSM 7.xx JSON structure
2. Missing proper expansion unit classification
3. No detailed SMART health metrics (beyond status)

## Required Code Changes

### In extractDrives() method:
```php
// Current (wrong):
$location = htmlspecialchars((string)($drive['location'] ?? 'main'), ENT_QUOTES);

// Should be:
$container = $drive['container']['str'] ?? 'Unknown';
$containerType = $drive['container']['type'] ?? '';
if ($containerType === 'internal') {
    $location = 'Main';
} else if ($containerType === 'ebox') {
    $location = $container;  // e.g., "RX1217rp-1"
} else {
    $location = 'Unknown';
}
```

### In extractDrives() for power-on hours:
```php
// Current (wrong):
$poh = (int)($drive['power_on_hours'] ?? 0);

// Should be:
if (isset($drive['power_on_hours']) && $drive['power_on_hours'] !== null) {
    $poh = (int)$drive['power_on_hours'];
} else {
    // DSM 7.xx doesn't provide POH in load_info - mark as unavailable
    $poh = -1;  // or null, indicate "not available"
}

// In template, display as:
$pohDisplay = ($poh === -1 || $poh === null) ? 'N/A' : ($poh . 'h');
```

## Conclusion

The drive analysis has **one critical issue** (location classification) and **one data gap** (POH availability in DSM 7.xx). Both should be addressed to provide accurate hardware assessment.
