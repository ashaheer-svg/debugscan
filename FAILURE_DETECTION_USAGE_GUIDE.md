# RAID Failure Detection - Usage Guide

## Quick Start

The failure detection system is automatically invoked when extracting hardware specifications. No additional configuration needed.

```php
use App\DeepDive\Hardware\HardwareSpecExtractor;

$extractor = new HardwareSpecExtractor();
$spec = $extractor->extract('/path/to/extracted/data');

// Failure data is now populated in:
// - $spec->failures          (classified failures per device)
// - $spec->failurePatterns   (pattern analysis per RAID array)
// - $spec->raidFailureLogs   (raw failure events from logs)
```

## Understanding the Output

### failures Array

Each device in the system gets a classification:

```php
$spec->failures['sdea'] = [
    'device' => 'sdea',
    'bay' => 5,
    'location' => 'expansion_unit_1',
    'model' => 'WDC WD60PURZ-85JURB1',
    'current_serial' => 'WW631P8V',
    'current_status' => 'failed',
    'snapshot_status' => 'normal',
    
    // Failure history from logs
    'failure_history' => [
        '2025-04-27T22:59:19',
    ],
    
    // One of these classifications:
    'failure_classification' => 'currently_failed',
    'status_detail' => 'Drive failed in logs and remains failed in current state',
    
    // Pattern information
    'pattern_type' => 'systemic',
    'pattern_presumed_cause' => 'shared_failure_source_power_connection_enclosure',
    'raid_array' => 'md2',
    
    // If replaced:
    'replacement_history' => [
        [
            'original_serial' => 'WW631P8V',
            'replacement_serial' => 'XYZ999999',
            'replacement_indication' => 'serial_mismatch',
        ]
    ]
];
```

### failurePatterns Array

One entry per RAID array that has failures:

```php
$spec->failurePatterns['md2'] = [
    'raid_array' => 'md2',
    'pattern_type' => 'systemic',      // or 'staggered', 'single_device'
    'total_failures' => 4,
    'affected_devices' => ['sdea', 'sdeb', 'sdec', 'sded'],
    'device_count' => 4,
    'presumed_cause' => 'shared_failure_source_power_connection_enclosure',
    'failure_groups' => [
        [
            // All failures at same timestamp
            ['timestamp' => '2025-04-27T22:59:19', 'device' => 'sdea', ...],
            ['timestamp' => '2025-04-27T22:59:19', 'device' => 'sdeb', ...],
            ['timestamp' => '2025-04-27T22:59:19', 'device' => 'sdec', ...],
            ['timestamp' => '2025-04-27T22:59:19', 'device' => 'sded', ...],
        ]
    ]
];
```

### raidFailureLogs Array

Raw events extracted from logs:

```php
$spec->raidFailureLogs = [
    [
        'timestamp' => '2025-04-27T22:59:19',
        'device' => 'sdea',
        'raid_array' => 'md2',
        'error_type' => 'read_error',
        'sector' => 12345,
        'raw_line' => 'Apr 27 22:59:19 kernel: [mdadm] md2: read error...',
    ],
    // ... more entries
];
```

## Failure Classifications Explained

### no_failure_record
- ✓ Drive has never failed
- Status: Healthy and operational
- Action: None required

### currently_failed
- ✕ Drive is currently failed
- Logs show failure event(s)
- mdstat confirms device marked as failed
- Action: Investigate and replace

### historically_failed_now_operational
- ◈ Drive failed in the past but recovered
- Logs show failure event(s)
- Current status is healthy
- Action: Monitor closely, may fail again

### replaced_after_failure
- ⚠ Drive was replaced
- Historical serial ≠ current serial
- Indicates physical replacement occurred
- Action: Confirm replacement was correct

### current_failure_no_log_record
- ⚠ Drive failed but no log entry
- mdstat shows device failed
- Logs don't contain failure record
- Action: Investigate unusual failure pattern

## Pattern Types Explained

### Systemic Pattern ⚡
- **When**: All drives fail at EXACT same timestamp (±2 seconds)
- **Cause**: Shared failure source (power, connection, enclosure)
- **Example**: All 4 expansion drives fail 2025-04-27T22:59:19
- **Action**: Check power supply, connections, enclosure status

### Staggered Pattern ⚠
- **When**: Drives fail at different times
- **Cause**: Individual component degradation
- **Example**: Drive 1 fails Apr 10, Drive 2 fails Apr 15, Drive 3 fails Apr 22
- **Action**: Normal wear, replace as needed

### Single Device Pattern ◎
- **When**: Only one drive failed
- **Cause**: Individual drive failure
- **Example**: Only sda failed, others healthy
- **Action**: Replace the drive

## Reading the Report

The HTML report displays failure information in three places:

### 1. Drive Failure Analysis Table (in Hardware Configuration)
Shows each drive with failure status:
- Bay and Location
- Device name
- Model and Serial
- Status badge (✕ Currently Failed, ⚠ Replaced, ◈ Recovered, etc.)
- Detailed status explanation

### 2. Failure Pattern Analysis Table (in Hardware Configuration)
Shows pattern analysis by RAID array:
- Array name (md0, md1, md2, etc.)
- Pattern type (Systemic, Staggered, Single)
- Number of devices affected
- Number of failure events
- Presumed cause and affected device list

### 3. RAID Arrays Table (existing section)
Shows current RAID health:
- Array state (Active, Degraded, etc.)
- Number of healthy vs missing members
- Rebuild progress if applicable

## Combining Information for Diagnosis

**Example: Expansion Unit Power Failure**

1. **Look at Failure Pattern table**:
   - Array md2: Pattern = Systemic
   - Devices affected: sdea, sdeb, sdec, sded (all 4 expansion drives)
   - Presumed cause: shared_failure_source_power_connection_enclosure
   - Conclusion: **This is NOT 4 individual drive failures, but 1 enclosure issue**

2. **Look at Drive Failure Analysis table**:
   - Bay 5 (sdea): Currently Failed, Serial WW631P8V
   - Bay 6 (sdeb): Currently Failed, Serial WW631PHC
   - Bay 7 (sdec): Currently Failed, Serial WW631PQX
   - Bay 8 (sded): Currently Failed, Serial WW631QRF
   - Conclusion: **All 4 expansion bays are down**

3. **Action Required**:
   - Check expansion unit power supply
   - Reseat power connector
   - Check USB/eSATA connection to main unit
   - Verify enclosure firmware version

**Example: Individual Drive Wear**

1. **Look at Failure Pattern table**:
   - Array md0: Pattern = Staggered
   - Devices affected: sda, sdb, sdc (different times)
   - Presumed cause: individual_component_failures
   - Conclusion: **Normal wear pattern, drives failing independently**

2. **Look at Drive Failure Analysis table**:
   - Bay 1 (sda): Recovered, failed 2025-04-10
   - Bay 2 (sdb): Currently Failed, failed 2025-04-15
   - Bay 3 (sdc): Currently Failed, failed 2025-04-22
   - Conclusion: **Drives failing over time, not simultaneous**

3. **Action Required**:
   - Replace failed drives (sdb, sdc)
   - Monitor sda for recurring failures
   - Consider replacing older drives proactively

**Example: Drive Replacement After Failure**

1. **Look at Drive Failure Analysis table**:
   - Bay 5 (sdea): Replaced After Failure
   - Original Serial: WW631P8V
   - Current Serial: XYZ999999
   - Conclusion: **Drive was replaced**

2. **Verify Replacement**:
   - Original failure date in logs
   - Current serial confirms replacement
   - New drive model may differ
   - Status should be healthy if replacement successful

3. **Action Required**:
   - None if current status is healthy
   - Monitor new drive for stability
   - Old drive should be discarded securely

## Common Questions

### Q: Why does the report show a drive as "Recovered" when it was replaced?

**A**: If we can't access the serial number history from the logs, we detect recovery based on:
- Failure record exists in logs
- Current status is healthy
- Same serial still in bay (if serial available)

To confirm replacement, look for serial number change.

### Q: Can the system tell me which specific drive failed?

**A**: Yes! For each failed drive, you'll see:
- **Bay number** (1-8 for main unit, varies for expansion)
- **Device name** (sdea, sda, etc.)
- **Model name** (e.g., "WDC WD60PURZ-85JURB1")
- **Serial number** (for identification/warranty claims)

### Q: How do I know if all drives failed at once or at different times?

**A**: Look at the **Failure Pattern Analysis** table:
- **Systemic** = All at same timestamp (likely power issue)
- **Staggered** = Different timestamps (normal wear)

### Q: What if I see "Unrecorded Failure"?

**A**: The drive shows as failed in mdstat, but we can't find it in the logs. This could mean:
- Log files were rotated before bundle captured
- Recent failure not yet logged
- Unusual failure condition

Investigate by:
- Checking drive physical status
- Looking at kernel dmesg if available
- Examining smart data if captured

### Q: Can I compare before/after to track drive replacements?

**A**: Yes! Look at the **replacement_history** field:
- Original serial from when it failed
- Current serial from recent snapshot
- If different = drive was replaced

## Integration with Monitoring

For ongoing monitoring:

```php
// Check if any critical failures
$criticalFailures = array_filter(
    $spec->failures,
    fn($f) => $f['failure_classification'] === 'currently_failed'
);

if (!empty($criticalFailures)) {
    // Alert: Active failures detected
}

// Check for systemic patterns
$systemicPatterns = array_filter(
    $spec->failurePatterns,
    fn($p) => $p['pattern_type'] === 'systemic'
);

if (!empty($systemicPatterns)) {
    // Alert: Systemic failure detected, check shared resources
}
```

## Data Sources

All failure data comes from REAL extractions, not assumptions:

| Data | Source | DSM 6 | DSM 7 |
|------|--------|-------|-------|
| Failure events | `/var/log/messages` | ✓ | ✓ |
| Current state | `/proc/mdstat` | ✓ | ✓ |
| Serial numbers | `/dev/disk/by-id/`, logs | ✓ | ✓ |
| Drive status | `load_info.result` | ✓ | ✓ |

No assumptions about:
- Bay counts (uses actual extracted data)
- Drive models (uses snapshot data)
- Failure causes (inferred from patterns only)
- RAID levels (extracted from configuration)
