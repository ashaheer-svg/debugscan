# RAID Failure Detection & Serial Tracking Implementation

## Summary

Complete implementation of expansion unit failure detection logic that distinguishes systemic failures (all drives fail simultaneously due to power/connection issues) from individual drive failures (drives fail at different times due to component degradation).

## Components Implemented

### 1. Core Data Extraction Methods (HardwareSpecExtractor.php)

#### `parseRAIDFailureLogs(): array`
- **Purpose**: Extract all RAID failure events from system logs with timestamps
- **Sources**: 
  - `/var/log/messages` (primary, DSM 6/7)
  - `/var/log/syslog` (fallback)
  - `/var/log/kern.log` (fallback)
- **Output**: Array of failure events with:
  - timestamp (normalized to ISO 8601)
  - device name (sdea, sda, etc.)
  - raid_array (md0, md1, md2, etc.)
  - error_type (read_error, write_error, timeout, disk_failure, uncorrectable_error)
  - sector (if available)

**Log Format Detection**:
- Handles syslog format: "Apr 27 22:59:19"
- Handles ISO format: "2025-04-27T22:59:19"
- Extracts kernel log error patterns

#### `parseMdstat(): array<string, array>`
- **Purpose**: Get current RAID array state from /proc/mdstat
- **Returns**: Current status of all RAID arrays including failed devices
- **Key Info**:
  - Array name (md0, md1, md2)
  - Array status (active, degraded, recovery, etc.)
  - RAID level (raid0, raid1, raid5, raid6)
  - Device list with status flags (F=failed, S=spare, ok)

#### `extractHistoricalSerialNumbers(): array`
- **Purpose**: Build timeline of which serial numbers were in which devices
- **Sources**:
  1. `/dev/disk/by-id/` symlinks (encode serial in name)
  2. Kernel detection logs (model and serial from boot messages)
  3. SMART data history (if captured)
- **Returns**: Timeline of serial numbers per device

### 2. Pattern Detection

#### `detectFailurePatterns(array $failures): array`
- **Purpose**: Analyze failure timestamps to identify patterns
- **Pattern Types**:
  - **Systemic**: All devices fail at same timestamp (±2 second tolerance)
    - Presumed cause: power supply, connection, or enclosure failure
  - **Staggered**: Devices fail at different times
    - Presumed cause: individual component failures
  - **Single Device**: Only one device failed
    - Presumed cause: individual drive failure

#### `groupFailuresByTimestamp(array $failures): array`
- **Purpose**: Group failures that occurred at approximately same time
- **Tolerance**: ±2 seconds to account for log buffering
- **Returns**: Grouped failure events

### 3. Correlation & Classification

#### `correlateWithSnapshot(array $drives, array $patterns, array $mdstatData): array`
- **Purpose**: Combine snapshot data, logs, and current state into final classification
- **Inputs**:
  - `$drives`: Current drive info from load_info.result
  - `$patterns`: Analyzed failure patterns from logs
  - `$mdstatData`: Current RAID state from mdstat
  
- **Output**: Classified failures for each drive with:
  - `device`: Device name
  - `bay`: Bay location
  - `location`: main or expansion unit
  - `current_serial`: Serial number at snapshot time
  - `failure_history`: Timestamps when drive failed (from logs)
  - `is_currently_failed`: Whether device shows failed in mdstat
  
  - **failure_classification** values:
    - `no_failure_record`: Never failed, healthy drive
    - `currently_failed`: Failed in logs and still failed now
    - `historically_failed_now_operational`: Failed then recovered/replaced
    - `replaced_after_failure`: Drive was swapped (serial mismatch)
    - `current_failure_no_log_record`: Failed now but not in logs (unusual)
  
  - `status_detail`: Human-readable explanation
  - `pattern_type`: systemic, staggered, or single_device
  - `pattern_presumed_cause`: Explanation of likely root cause
  - `replacement_history`: If serial changed, tracks original vs current

### 4. Data Model Updates

#### HardwareSpec.php - New Properties
```php
public array $failures = [];              // Classified failures by device
public array $failurePatterns = [];       // Pattern analysis by RAID array
public array $raidFailureLogs = [];       // Raw failure events from logs
```

#### Integration in extract() Method
```php
// 1. Parse failure logs
$raidFailureLogs = $this->parseRAIDFailureLogs();

// 2. Detect patterns
$failurePatterns = $this->detectFailurePatterns($raidFailureLogs);

// 3. Get current state
$mdstatData = $this->parseMdstat();

// 4. Correlate with snapshot
$failures = $this->correlateWithSnapshot($spec->drives, $failurePatterns, $mdstatData);
```

### 5. Report Rendering

#### ReportRenderer.php - New Sections

**Drive Failure Analysis Table**:
- Shows each drive with failure history
- Displays classification (Currently Failed, Replaced, Recovered, etc.)
- Includes status badges with color coding
- Shows serial numbers for replacement tracking

**Failure Pattern Analysis Table**:
- One row per RAID array with failures
- Shows pattern type (Systemic ⚡, Staggered ⚠, Single ◎)
- Lists affected devices
- Shows presumed cause
- Example:
  ```
  Array | Pattern   | Devices | Failures | Cause
  md2   | Systemic  | 4       | 4        | shared_failure_source_power_connection_enclosure
        |           |         |          | sdea, sdeb, sdec, sded
  ```

## Example Scenarios

### Scenario 1: Systemic Expansion Unit Failure
**Log data**:
```
2025-04-27T22:59:19 sdea failed in md2 (read error)
2025-04-27T22:59:19 sdeb failed in md2 (read error)
2025-04-27T22:59:19 sdec failed in md2 (read error)
2025-04-27T22:59:19 sded failed in md2 (read error)
```

**Detection**:
- All 4 devices fail at exact same timestamp
- Pattern: SYSTEMIC
- Presumed cause: Expansion unit power/connection issue
- Report shows: All 4 drives failed simultaneously in expansion unit enclosure

### Scenario 2: Individual Drive Replacement
**Log data**:
```
2025-04-10T14:23:45 sda failed in md0 (write error)
Historical serial: WW631P8V
```

**Current state**:
```
Current serial in sda bay: XYZ999999
mdstat shows: sda is failed
```

**Detection**:
- Drive failed on Apr 10
- Current serial is different from failure log serial
- Conclusion: Drive was replaced after failure
- Report shows: "Replaced After Failure"

### Scenario 3: Staggered Failures (Wear Pattern)
**Log data**:
```
2025-04-10T14:23:45 sda failed in md0
2025-04-15T08:12:33 sdb failed in md0
2025-04-22T19:44:22 sdc failed in md0
```

**Detection**:
- Failures spread over 12 days
- Pattern: STAGGERED
- Presumed cause: Individual component degradation
- Report shows: Normal wear pattern, drives failing over time

### Scenario 4: Historical Failure + Recovery
**Log data**:
```
2025-04-27T22:59:19 sdea failed in md2
```

**Current state**:
```
sdea serial: WW631P8V (same as failure log)
mdstat shows: sdea is OK (not failed)
```

**Detection**:
- Drive failed but recovered (RAID rebuild completed)
- Same serial still in bay
- Conclusion: Historical failure, now operational
- Report shows: "Recovered" with original failure timestamp

## DSM 6 & 7 Compatibility

All methods include fallback chains:

**Log file paths**:
- `/dsm/var/log/messages` (both DSM 6 & 7)
- `/dsm/var/log/syslog` (fallback)
- `/dsm/var/log/kern.log` (fallback)

**Timestamp formats**:
- Syslog format: "Apr 27 22:59:19" (no year, will be inferred)
- ISO format: "2025-04-27T22:59:19" (explicit timestamp)
- Kernel format variations handled

**Serial extraction**:
- Multiple sources checked in order
- Works with or without explicit serial fields
- Graceful degradation if any source unavailable

## Facts-Based Approach

All decisions based on extracted data, NO assumptions:

✅ **DO**:
- Extract only what's in logs
- Use actual timestamps from logs
- Record devices exactly as named
- Compare serial numbers directly
- Document data source for each finding

❌ **DON'T**:
- Assume failure cause without pattern evidence
- Guess at bay counts
- Assume default configurations
- Make judgments without complete data
- Use max() functions for defaults

## Integration Points

### Called from: `HardwareSpecExtractor->extract()`
```php
// After expansion units extracted, before returning:
$raidFailureLogs = $this->parseRAIDFailureLogs();
$failurePatterns = $this->detectFailurePatterns($raidFailureLogs);
$mdstatData = $this->parseMdstat();
$spec->failures = $this->correlateWithSnapshot(...);
```

### Used by: `ReportRenderer->renderHardwareSpecs()`
```php
// After RAID config, before volumes:
$failureAnalysisTable = renderFailureAnalysis($spec->failures);
$patternAnalysisTable = renderFailurePatterns($spec->failurePatterns);
```

## Key Methods Reference

| Method | Input | Output | Purpose |
|--------|-------|--------|---------|
| `parseRAIDFailureLogs()` | — | Array of failure events | Extract historical failures from logs |
| `parseMdstat()` | — | Array of RAID states | Get current failure status |
| `extractHistoricalSerialNumbers()` | — | Serial number timeline | Build device history |
| `detectFailurePatterns()` | Failure events | Pattern analysis | Identify systemic vs individual failures |
| `groupFailuresByTimestamp()` | Failures | Grouped failures | Cluster failures by time |
| `correlateWithSnapshot()` | Drives, patterns, mdstat | Classified failures | Final failure classification |

## Testing Checklist

- [ ] Test with sample bundle containing RAID failures
- [ ] Verify systemic pattern detection (all devices same timestamp)
- [ ] Verify staggered pattern detection (different timestamps)
- [ ] Verify serial number tracking (replacement detection)
- [ ] Verify historical vs current classification
- [ ] Test with DSM 6 log format
- [ ] Test with DSM 7 log format
- [ ] Verify report renders without errors
- [ ] Check CSS styling for new tables
- [ ] Verify no data is assumed (facts-only)

## Files Modified

1. **HardwareSpecExtractor.php**
   - Added 6 public/private methods for failure analysis
   - ~600 lines of new code
   - DSM 6/7 compatibility built-in

2. **HardwareSpec.php**
   - Added 3 new properties for failure data
   - Updated toArray() method
   - Minor changes, mostly additive

3. **ReportRenderer.php**
   - Added 2 new report sections (failure analysis, patterns)
   - Added corresponding CSS for new tables
   - Integrated into renderHardwareSpecs() method

## Next Steps

1. Deploy to test environment
2. Run against sample bundles with known failures
3. Validate pattern detection accuracy
4. Verify serial tracking works across DSM versions
5. Test edge cases (no logs, partial data, etc.)
6. Integration testing with full pipeline
