# File Discovery Implementation - Detailed Code Changes

## Overview

All five power-related file parsers have been updated to use robust file discovery via `locateFile()` helper. This solves the "0 power analysis(s)" problem by finding power data files regardless of bundle extraction layout.

---

## Helper Method (Already Implemented)

Located at line 176 in PowerSupplyParser.php:

```php
private function locateFile(string $basePath, array $patterns): ?string
{
    $basePath = rtrim($basePath, '/\\');

    foreach ($patterns as $pattern) {
        // Direct file check (no glob)
        if (strpos($pattern, '*') === false) {
            $fullPath = $basePath . '/' . $pattern;
            if (file_exists($fullPath)) {
                return $fullPath;
            }
        } else {
            // Glob pattern
            $globPath = $basePath . '/' . $pattern;
            $matches = glob($globPath);
            if ($matches && count($matches) > 0) {
                // Return first match (should be only one for dmidecode)
                foreach ($matches as $match) {
                    if (is_file($match)) {
                        return $match;
                    }
                }
            }
        }
    }

    return null;
}
```

---

## Change 1: parseDmiPowerSupply() - Lines 268-274

### Before:
```php
$dmiFile = $extractedPath . '/dsm/result/dmidecode.result';
if (!file_exists($dmiFile)) {
    return ['data' => $data, 'citations' => $citations];
}
```

### After:
```php
// Search for dmidecode.result across multiple possible locations
// to handle different bundle extraction layouts (DSM 6/7, nested dirs, etc.)
$dmiFile = $this->locateFile($extractedPath, [
    'dsm/result/dmidecode.result',
    'result/dmidecode.result',
    'dmidecode.result',
    '*/result/dmidecode.result',
    '*/dmidecode.result',
]);

if (!$dmiFile) {
    return ['data' => $data, 'citations' => $citations];
}
```

### Why This Works:
- **Direct paths first**: Checks common paths before globbing (faster)
- **Glob patterns last**: Catches nested/alternate directory structures
- **Fallback**: If nothing found, gracefully returns empty data instead of failing

---

## Change 2: parseIpmiVoltage() - Lines 397-404

### Before:
```php
$ipmiFile = $extractedPath . '/dsm/result/ipmi_sensors.result';
if (!file_exists($ipmiFile)) {
    return ['data' => $data, 'citations' => $citations];
}
```

### After:
```php
// Search for IPMI sensors file across multiple possible locations
$ipmiFile = $this->locateFile($extractedPath, [
    'dsm/result/ipmi_sensors.result',
    'result/ipmi_sensors.result',
    'ipmi_sensors.result',
    '*/result/ipmi_sensors.result',
    '*/ipmi_sensors.result',
]);

if (!$ipmiFile) {
    return ['data' => $data, 'citations' => $citations];
}
```

### Impact:
Now detects voltage readings from IPMI sensors regardless of directory nesting or DSM version.

---

## Change 3: parseIpmiCurrent() - Lines 442-449

### Before:
```php
$ipmiFile = $extractedPath . '/dsm/result/ipmi_sensors.result';
if (!file_exists($ipmiFile)) {
    return ['data' => $data, 'citations' => $citations];
}
```

### After:
```php
// Search for IPMI sensors file across multiple possible locations
$ipmiFile = $this->locateFile($extractedPath, [
    'dsm/result/ipmi_sensors.result',
    'result/ipmi_sensors.result',
    'ipmi_sensors.result',
    '*/result/ipmi_sensors.result',
    '*/ipmi_sensors.result',
]);

if (!$ipmiFile) {
    return ['data' => $data, 'citations' => $citations];
}
```

### Impact:
Detects current/amperage readings and over-current conditions regardless of extraction layout.

---

## Change 4: parseIpmiEventLog() - Lines 481-495

### Before:
```php
$selFile = $extractedPath . '/dsm/result/ipmi_event_log.result';
if (!file_exists($selFile)) {
    return ['data' => $data, 'citations' => $citations];
}
```

### After:
```php
// Search for IPMI event log file across multiple possible locations
$selFile = $this->locateFile($extractedPath, [
    'dsm/result/ipmi_event_log.result',
    'result/ipmi_event_log.result',
    'ipmi_event_log.result',
    '*/result/ipmi_event_log.result',
    '*/ipmi_event_log.result',
    'dsm/result/ipmi_sel.result',
    'result/ipmi_sel.result',
    'ipmi_sel.result',
]);

if (!$selFile) {
    return ['data' => $data, 'citations' => $citations];
}
```

### Impact:
Finds power-related IPMI events even if file is named differently (ipmi_sel.result vs ipmi_event_log.result).

---

## Change 5: parseChassisStatus() - Lines 573-583

### Before:
```php
$chassisFile = $extractedPath . '/dsm/result/ipmi_chassis_status.result';
if (!file_exists($chassisFile)) {
    return ['data' => $data, 'citations' => $citations];
}
```

### After:
```php
// Search for IPMI chassis status file across multiple possible locations
$chassisFile = $this->locateFile($extractedPath, [
    'dsm/result/ipmi_chassis_status.result',
    'result/ipmi_chassis_status.result',
    'ipmi_chassis_status.result',
    '*/result/ipmi_chassis_status.result',
    '*/ipmi_chassis_status.result',
]);

if (!$chassisFile) {
    return ['data' => $data, 'citations' => $citations];
}
```

### Impact:
Detects chassis-level power state and thermal status regardless of directory structure.

---

## Test Bundle Layout Examples

The updated code now handles these extraction layouts:

### Layout 1: DSM 6.x (Original Expected)
```
/extracted/uuid/
├── dsm/
│   └── result/
│       ├── dmidecode.result
│       ├── ipmi_sensors.result
│       ├── ipmi_event_log.result
│       └── ipmi_chassis_status.result
```
✅ Found by: Direct path check

### Layout 2: DSM 7.x (Nested result/)
```
/extracted/uuid/
├── result/
│   ├── dmidecode.result
│   ├── ipmi_sensors.result
│   ├── ipmi_event_log.result
│   └── ipmi_chassis_status.result
```
✅ Found by: Second pattern `result/...`

### Layout 3: Flat (No subdirs)
```
/extracted/uuid/
├── dmidecode.result
├── ipmi_sensors.result
├── ipmi_event_log.result
└── ipmi_chassis_status.result
```
✅ Found by: Third pattern `dmidecode.result`

### Layout 4: Timestamp-Nested (Variant)
```
/extracted/uuid/2026-04-30/
├── result/
│   └── dmidecode.result
```
✅ Found by: Glob pattern `*/result/dmidecode.result`

### Layout 5: Mixed (Some under dsm, some not)
```
/extracted/uuid/
├── result/
│   └── dmidecode.result
├── ipmi_sensors.result
├── ipmi_event_log.result
```
✅ Found by: Multiple patterns try in order, first match wins

---

## Backward Compatibility

✅ **Fully compatible**
- No schema changes
- No changes to returned data structure
- No breaking changes to callers
- Gracefully handles missing files (same as before)
- Existing code paths unaffected

---

## Deployment Checklist

- [ ] Backup current src/Parsers/PowerSupplyParser.php
- [ ] Copy updated PowerSupplyParser.php to src/Parsers/
- [ ] Clear application cache
- [ ] Restart application server
- [ ] Generate test report with JJeth bundle
- [ ] Verify "power analysis(s)" > 0 (not 0)
- [ ] Verify anomalies detected correctly
- [ ] Check application logs for errors

---

## Verification Commands

After deployment:

```bash
# Check that locateFile exists (already in code)
grep -c "function locateFile" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
# Expected: 1

# Check that all methods use locateFile now
grep -c "locateFile.*dmidecode\|locateFile.*ipmi_sensors\|locateFile.*ipmi_event\|locateFile.*ipmi_chassis" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
# Expected: 5 (or more if tested multiple times)

# Quick visual check
grep -n "\$this->locateFile" /var/www/ai-debugscan3/src/Parsers/PowerSupplyParser.php
# Should show 5 calls
```

---

## Files Modified

- ✅ **src/Parsers/PowerSupplyParser.php** - All power file discovery updated

---

## Next Steps

1. Deploy updated PowerSupplyParser.php to production
2. Clear cache and restart application
3. Run deepscan on test bundle with power data
4. Verify reports show correct power anomalies
5. Monitor application logs for any issues

Once deployed and tested, power anomaly detection will work correctly across all bundle extraction layouts.
