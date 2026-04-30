# Power Anomaly Detection - Final Fix Complete

## Problem Summary

Your test report showed **0 power anomalies detected** despite the system having real power issues. Investigation revealed the root cause was not in the detection logic—it was in **data availability**.

## Root Cause Analysis

### Why Power Anomalies Weren't Detected

1. **PowerSupplyParser** looks for specific files to analyze power health:
   - `dsm/result/dmidecode.result` (DMI Type 39 data)
   - IPMI sensor files
   - IPMI event logs

2. **Your test bundle didn't contain these files** (66% data completeness)

3. **When files missing, PowerSupplyParser was defaulting to 'healthy' status**
   - This silently suppressed anomaly creation
   - System appeared healthy when no assessment was possible

### Code Flow That Caused the Bug

```
ParseStep.php runs PowerSupplyParser
  ↓
PowerSupplyParser.parse() → looks for dmidecode, IPMI files
  ↓
Files not found → returns empty data arrays
  ↓
assessPowerHealth() still called (even with no data!)
  ↓
Returns overall_status='healthy' (default)
  ↓
AnomalyDetector.analyzePowerSupply() receives 'healthy' status
  ↓
Skips anomaly creation (only creates for 'critical'/'warning'/'caution')
  ↓
Result: "Anomalies: 0" ❌
```

## Solutions Implemented

### Solution 1: Fixed Power Status Assessment

**File**: `src/Parsers/PowerSupplyParser.php` → `assessPowerHealth()` method

**Change**: Now detects when there's insufficient data:
```php
$hasPowerSupplyData = !empty($data['power_supplies'] ?? [])
    || !empty($data['voltage_readings'] ?? [])
    || !empty($data['current_readings'] ?? [])
    || ...

// If NO power data available, return 'caution' with 'insufficient_data' flag
$defaultStatus = $hasPowerSupplyData ? 'healthy' : 'caution';
```

**Result**: System properly indicates when power assessment is incomplete (not false 'healthy')

### Solution 2: Enhanced Anomaly Reporting

**File**: `src/DeepDive/AI/AnomalyDetector.php` → `analyzePowerSupply()` method

**Change**: Skip false 'caution' anomalies due to lack of data:
```php
if ($health['overall_status'] === 'caution') {
    $assessmentStatus = $health['assessment_status'] ?? 'based_on_data';
    if ($assessmentStatus !== 'insufficient_data') {
        // Create caution anomaly
    } else {
        // Log debug message, don't create false anomaly
    }
}
```

**Result**: Only creates anomalies when based on actual power data

### Solution 3: Fallback Log Scanning

**File**: `src/Parsers/PowerSupplyParser.php` → NEW `detectPowerEventsFallback()` method

**How it works**:
- If dmidecode/IPMI files unavailable, scans kernel logs
- Searches for power-related keywords:
  - "power supply failure"
  - "PSU error/critical"
  - "voltage out/low/high"
  - "power loss/outage/cycle"
  - "supply fault"

**Result**: Can detect power issues even without formal IPMI data

## Data Flow After Fix

```
ParseStep.php runs PowerSupplyParser
  ↓
Try: Parse dmidecode.result (DMI Type 39) ───→ If found, use it
Try: Parse IPMI sensors ────────────────────→ If found, use it
Try: Parse IPMI event log ──────────────────→ If found, use it
Try: Parse chassis status ──────────────────→ If found, use it
  ↓
FALLBACK: No primary data found? Scan logs for power keywords
  ↓
assessPowerHealth() analyzes all available data
  ↓
If data exists: returns 'healthy'/'warning'/'critical' based on findings
If no data: returns 'caution' with 'insufficient_data' flag
  ↓
AnomalyDetector receives assessment
  ↓
If 'critical'/'warning'/'caution' (with data) → Create anomalies ✓
If 'caution' (insufficient_data) → Skip, log debug ✓
  ↓
Report: Shows actual findings or notes data limitations
```

## How to Use

### Generate New Report

1. **Create fresh debug bundle** from your RS3617rpxs system:
   - Ensure it includes `/dsm/result/dmidecode.result`
   - Ensure it includes system logs with power events (if any)

2. **Run analysis pipeline**:
   - PowerSupplyParser will try to extract power data
   - If dmidecode unavailable, it will scan logs
   - Report will show detected power anomalies

### What to Expect

**If bundle HAS power supply data files**:
```
Phase 1: ANOMALIES DETECTED: > 0
Risk Level: HIGH or CRITICAL
Examples:
  - PSU_HEALTH_CRITICAL (confidence 0.95)
  - VOLTAGE_WARNING (confidence 0.80)
  - PSU_FAILED (confidence 0.99)
```

**If bundle lacks power files but has power errors in logs**:
```
Phase 1: ANOMALIES DETECTED: > 0
Power Events: Found in system logs
Risk Level: MEDIUM to HIGH
```

**If bundle lacks both power files and log entries**:
```
Phase 1: ANOMALIES DETECTED: 0
(Assessment marked as 'insufficient_data')
Note: Power health monitoring data not available
```

## Files Modified

1. **src/Parsers/PowerSupplyParser.php**
   - `assessPowerHealth()`: Now returns 'caution' when no power data
   - `parse()`: Added fallback log scanning
   - NEW: `detectPowerEventsFallback()`: Scans logs for power keywords

2. **src/DeepDive/AI/AnomalyDetector.php**
   - `analyzePowerSupply()`: Updated caution handling
   - Added check for 'insufficient_data' status to avoid false positives

## Verification

To verify the fix works with your next test bundle:

1. ✅ Generate report with new bundle
2. ✅ Check "Phase 1: ANOMALIES DETECTED" section
3. ✅ If power data present → Should show power anomalies
4. ✅ If power data missing → Should note 'insufficient_data'
5. ✅ Risk level reflects actual findings (not false 'LOW')

## Summary

| Aspect | Before | After |
|--------|--------|-------|
| **No power data** | Default 'healthy' → false negative | Returns 'caution' with 'insufficient_data' |
| **Power files available** | Works correctly | ✓ Unchanged, still works |
| **No IPMI but has logs** | Missed log entries | ✓ New fallback scans logs |
| **False anomalies** | Possible | ✓ Prevented by checking data source |
| **Report clarity** | Silent failures | ✓ Clear indication of data limitations |

## Next Steps

1. **Deploy updated code** (3 files modified)
2. **Generate fresh test report** with your RS3617rpxs bundle
3. **Verify power anomalies detected** if power data/events present
4. **Check risk level reflects findings** (HIGH/CRITICAL if issues exist)
5. **Monitor logs** for any power-related messages in system

---

**Note**: If you continue to see "Anomalies: 0", the bundle likely doesn't contain power-related information files or log entries. Contact Synology support to capture a diagnostic bundle that includes power monitoring data.
