# Power Detection Test - JJeth Bundle Analysis

## Bundle Status

✅ **JJeth.dat.zip extracted and ready for testing**
- Location: `/sessions/brave-vigilant-cannon/mnt/ai-debugscan3/sample/extract/`
- Size: 103MB of diagnostic data
- Contains: dmidecode.result with power supply information

## Power Supply Data Found

The JJeth bundle contains **CRITICAL power supply failure data**:

```
DMI Type 39 - System Power Supply
  Status: Not Present       ← CRITICAL!
  Plugged: No              ← CRITICAL!
  Max Power Capacity: 75W
```

This should generate:
- **Anomaly Type**: `PSU_FAILED`
- **Severity**: `CRITICAL`
- **Confidence**: `0.99` (99%)

## Expected Detection Flow

1. **ParseStep** calls PowerSupplyParser.parse()
2. **PowerSupplyParser**:
   - Calls `parseDmiPowerSupply()`
   - Finds `/dsm/result/dmidecode.result`
   - Extracts DMI Type 39 section
   - Parses Status="Not Present" → `detection_status='not_present'`, `status='failed'`
   - Parses Plugged="No" → `plugged=false`
   - Returns power_supplies array with failed PSU

3. **assessPowerHealth()** evaluates:
   - 1 PSU found, status='failed'
   - Sets `overall_status='critical'`
   - Adds risk factor: "Single PSU: Not detected or failed"

4. **AnomalyDetector.analyzePowerSupply()**:
   - Receives `health_assessment` with `overall_status='critical'`
   - Creates anomaly: `PSU_HEALTH_CRITICAL`
   - Adds to findings

5. **Report Output**:
   - Phase 1: ANOMALIES DETECTED: **1** (not 0)
   - Risk Level: **CRITICAL** (not LOW)
   - Shows: "PSU #1: status=failed, detection=not_present, plugged=no"

## Why Current Report Shows 0 Anomalies

Your test bundle is **26ca1c81-0340-4d93-93af-f6a5bd3761a4** (different from JJeth)

This bundle:
- ✅ Has RAID configuration data
- ❌ Lacks dmidecode.result file
- ❌ Lacks IPMI sensor files
- ❌ Lacks power-related log entries

Without these source files, PowerSupplyParser returns empty data, and even with the fallback method, there's nothing to scan.

## How to Test Power Detection

### Method 1: Use JJeth Bundle Directly

The JJeth bundle is ready in:
```
/sessions/brave-vigilant-cannon/mnt/ai-debugscan3/sample/extract/dsm/
```

To trigger analysis with this bundle:
1. Run extraction with `/sample/extract/dsm/` as the source
2. PowerSupplyParser should detect the failed PSU
3. Report should show `Anomalies: 1` with PSU_HEALTH_CRITICAL

### Method 2: Generate Report from JJeth

If your system has a way to run the pipeline directly on the extracted bundle:
```bash
# Run with JJeth as input
/path/to/deepdive/cli analyze --bundle /sessions/brave-vigilant-cannon/mnt/ai-debugscan3/sample/extract/
```

## Code Changes Verification

The following fixes are in place:

**1. PowerSupplyParser.php** (lines 140-155)
- Added `$hasAnyPowerData` check
- Added fallback log scanning
- Returns proper assessment when data available

**2. PowerSupplyParser.php** (lines 524-568)
- assessPowerHealth() now distinguishes between:
  - 'healthy' - actual data, no issues
  - 'caution' with 'insufficient_data' - no data to assess
  - 'critical'/'warning' - actual issues found

**3. AnomalyDetector.php** (lines 180-198)
- Updated caution handling
- Skips false positives from insufficient data
- Only creates anomalies based on actual power data

## Testing Checklist

- [ ] Confirm PowerSupplyParser can access JJeth dmidecode.result
- [ ] Verify PSU status parsing: "Not Present" → 'failed'
- [ ] Verify PSU plugged parsing: "No" → false  
- [ ] Verify assessPowerHealth returns 'critical' for single failed PSU
- [ ] Verify AnomalyDetector creates PSU_HEALTH_CRITICAL anomaly
- [ ] Verify report shows Anomalies > 0
- [ ] Verify report shows Risk Level: CRITICAL

## Next Steps

1. **Run full pipeline** with the JJeth bundle as input
2. **Verify anomalies detected** appear in output
3. **Check risk assessment** reflects CRITICAL status
4. **If successful**: Power detection is working correctly
5. **If issues persist**: Check for:
   - File path mismatches
   - Regex parsing failures
   - Silent exceptions being caught

## Code Reference

- PowerSupplyParser: `src/Parsers/PowerSupplyParser.php`
- Anomaly Detection: `src/DeepDive/AI/AnomalyDetector.php`
- Pipeline Integration: `src/DeepDive/Pipeline/ParseStep.php` (line 149)
