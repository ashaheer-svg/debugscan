# Power Anomaly Detection Not Working — Root Cause Analysis

## Problem
Report shows "0 Anomalies" in Phase 1 AI analysis despite system having real power issues

## Root Cause Identified

### Architecture Issue: Data Flow Mismatch

The system has **two separate power analysis paths that don't communicate:**

#### Path 1: Power Supply Parsing (Works ✓)
```
ParseStep.php
  ↓
PowerSupplyParser.parse() extracts detailed power data
  ↓
Results include: voltage, current, events, health assessment
  ↓
Stored in: $bundle['power_data']
  ↓
Passed to: ReportRenderer for display
  ↓
RESULT: Power data DISPLAYS in report ✓
```

**File:** `src/Parsers/PowerSupplyParser.php` (line 98)
**Data Source:** DMI Type 39, IPMI sensors, IPMI event log, chassis status
**Output:** Comprehensive power health assessment

#### Path 2: Anomaly Detection (Broken ✗)
```
RenderStep.php
  ↓
AnomalyDetector.analyzeBundleAnomalies() called with:
  - bundlePath (directory with raw files)
  - psu_model (just a string)
  - hardware_spec (just a string)
  - bundle_timestamp (just a timestamp)
  ↓
AnomalyDetector tries to extract IPMI events from raw files
  ↓
IF IPMI files missing (data completeness 66%) → NO EVENTS FOUND
  ↓
NO EVENTS = NO ANOMALIES DETECTED
  ↓
RESULT: "0 Anomalies" ✗
```

**File:** `src/DeepDive/Pipeline/RenderStep.php` (lines 269-273)
**Issue:** NOT RECEIVING the power_data that was already parsed!

### The Disconnect

| Component | Data Available | Data Used |
|-----------|-----------------|-----------|
| PowerSupplyParser | ✓ Complete power data (voltage, current, events, assessment) | ✓ Used for display |
| AnomalyDetector | ✗ Only psu_model string | ✗ Tries to reparse from raw files |

PowerSupplyParser already did the hard work of extracting power data, but AnomalyDetector doesn't know about it!

---

## Why It's Not Detecting Power Issues

1. **Data Completeness 66%**
   - Your bundle is missing some files
   - IPMI files (ipmitool output, sel data) may not be present
   - Without IPMI files, extractIPMIEvents() returns empty array

2. **No IPMI Events = No Anomalies**
   - AnomalyDetector.analyzePowerSupply() looks for IPMI_PSU events (line 155)
   - If ipmi_events is empty, the foreach loop never executes
   - Result: anomalies array stays empty

3. **No Anomalies = Overall Risk = LOW**
   - Lines 105-109 of AnomalyDetector collect all anomalies
   - If power_supply anomalies is empty, overall_risk stays LOW
   - Report shows "0 Anomalies" and "LOW RISK"

---

## Solution: Connect the Two Paths

### Option A: Pass power_data to AnomalyDetector (Recommended)

**File to modify:** `src/DeepDive/Pipeline/RenderStep.php` (lines 269-273)

**Current code:**
```php
$phase1 = $detector->analyzeBundleAnomalies($bundlePath, [
    'psu_model'        => $bundle['psu_model'] ?? null,
    'hardware_spec'    => $bundle['hardware_spec'] ?? null,
    'bundle_timestamp' => $bundle['extracted_at'] ?? date('Y-m-d H:i:s'),
]);
```

**Fixed code:**
```php
$phase1 = $detector->analyzeBundleAnomalies($bundlePath, [
    'psu_model'        => $bundle['psu_model'] ?? null,
    'hardware_spec'    => $bundle['hardware_spec'] ?? null,
    'bundle_timestamp' => $bundle['extracted_at'] ?? date('Y-m-d H:i:s'),
    'power_data'       => $bundle['power_data'] ?? null,  // ADD THIS
]);
```

**Then modify:** `src/DeepDive/AI/AnomalyDetector.php`

Add logic to use power_data if available:
```php
private function analyzePowerSupply(array $logData): array
{
    $anomalies = [];
    $issues = [];

    // FIRST: Check structured power_data (from PowerSupplyParser)
    if (!empty($logData['power_data'] ?? null)) {
        $powerData = $logData['power_data'];
        
        // Check health assessment
        if (isset($powerData['health_assessment'])) {
            $health = $powerData['health_assessment'];
            
            // If overall_status is not healthy, flag anomalies
            if ($health['overall_status'] === 'critical') {
                $anomalies[] = [
                    'timestamp'    => date('Y-m-d H:i:s'),
                    'type'         => 'PSU_CRITICAL',
                    'severity'     => 'CRITICAL',
                    'confidence'   => 0.95,
                    'message'      => implode(', ', $health['risk_factors'] ?? []),
                ];
            }
            
            // Check voltage/current readings for anomalies
            if (!empty($powerData['voltage_readings'])) {
                foreach ($powerData['voltage_readings'] as $voltage) {
                    if (($voltage['status'] ?? 'ok') === 'critical') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'VOLTAGE_CRITICAL',
                            'severity'     => 'CRITICAL',
                            'confidence'   => 0.90,
                            'message'      => $voltage['rail_name'] . ': ' . $voltage['voltage_volts'] . 'V (out of spec)',
                        ];
                    }
                }
            }
        }
    }
    
    // FALLBACK: Check IPMI logs (existing logic)
    $psuEvents = array_filter(
        $logData['ipmi_events'] ?? [],
        fn($e) => ($e['type'] ?? '') === 'IPMI_PSU'
    );
    
    // ... rest of existing code ...
}
```

### Option B: Use PowerSupplyParser Data Directly

Instead of calling AnomalyDetector, check power_data health_assessment directly in ReportRenderer or RenderStep.

---

## Why This Happened

### Design Decision
- PowerSupplyParser was created to extract and analyze power data comprehensively
- AnomalyDetector was created to detect anomalies from logs using AI/statistical analysis
- They evolved independently without data sharing

### The Gap
- PowerSupplyParser → ReportRenderer: ✓ Direct path
- PowerSupplyParser → AnomalyDetector: ✗ No path

### Result
- Power data is parsed but not checked for anomalies by AI
- AI anomaly detection is bypassed for power issues
- Report displays power data but says "0 Anomalies"

---

## Testing After Fix

### Step 1: Add logging
```php
// In RenderStep.php after line 269
$ctx->logger->info('[deepdive.render] Power data passed to detector: ' . 
    json_encode($bundle['power_data']));
```

### Step 2: Check AnomalyDetector processes it
```php
// In AnomalyDetector.analyzePowerSupply() 
$this->log('debug', 'Power data available: ' . 
    (!empty($logData['power_data']) ? 'YES' : 'NO'));
```

### Step 3: Generate new report
- Should show anomalies if power health status is not healthy
- Should show proper risk level based on power_data assessment

### Step 4: Verify
Check report shows:
- ✓ Power anomalies detected
- ✓ Risk level reflects power issues
- ✓ Anomaly count > 0

---

## Impact Analysis

### Scope
- **Affects:** Power issue detection in Phase 1 AI analysis
- **Does NOT affect:** Power data display in report (already working)
- **Does NOT affect:** Other anomaly types (thermal, hardware, RAID)

### Systems Affected
All NAS models with power supply data:
- Rackmounts (IPMI-enabled)
- DiskStations with full debug data
- Systems where PowerSupplyParser extracts data

### Risk
- **Low:** This is fixing a data pipeline issue, not changing logic
- **Safe:** Using already-validated power data from PowerSupplyParser

---

## Related Code Sections

### PowerSupplyParser.php (line 98)
- `parse()`: Extracts complete power data
- Data structure defined in lines 31-82

### RenderStep.php (lines 269-273)
- Where AnomalyDetector is called
- Where fix should be applied (add power_data to metadata)

### AnomalyDetector.php (lines 147-200)
- `analyzePowerSupply()`: Where fix should be implemented
- Currently only checks IPMI events from logs
- Should also check structured power_data

### ParseStep.php (lines 155-168)
- Where power_data is extracted and stored
- Confirms power_data is available in bundle

---

## Deployment Plan

1. **Modify RenderStep.php**
   - Pass power_data to AnomalyDetector (1 line change)

2. **Modify AnomalyDetector.php**
   - Add logic to check power_data.health_assessment
   - Add logic to check voltage/current anomalies
   - Keep fallback to IPMI log parsing

3. **Test**
   - Generate report with test bundle
   - Verify anomalies detected if power health is critical

4. **Deploy**
   - No database changes
   - No API changes
   - Backward compatible

---

## Summary

| Aspect | Current | Fixed |
|--------|---------|-------|
| **Power data extraction** | ✓ Works | ✓ Works |
| **Power data display** | ✓ Works | ✓ Works |
| **Power anomaly detection** | ✗ Broken | ✓ Fixed |
| **Anomaly count** | 0 | > 0 (if power issues exist) |
| **Risk level** | LOW | Reflects power health |

The fix connects two existing systems that should have been sharing data from the start.
