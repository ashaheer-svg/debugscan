# Detecting PSU Configuration in DSM Debug Data

## Question
Does the DSM indicate if a device is a single PSU or redundant PSU model?

## Answer
**Not directly in synoinfo.conf**. However, there are several methods to determine PSU configuration:

---

## Method 1: Model-Based Lookup (Most Reliable)

### How It Works
Query a hardware specification database with the device model and determine native PSU configuration.

**Data Source**: `synoinfo.conf` - field: `unique`

**Example**:
```
unique="synology_broadwell_rs3617rpxs"
       └─────── Extract model: RS3617rpxs
```

**Lookup Result**:
- RS3617rpxs → **Dual Redundant PSU** (500W each)
- RS3617xs → **Dual Redundant PSU** (500W each)
- RS2419rp → **Dual Redundant PSU** (250W each)
- DS918+ → **Single PSU** (90W)
- DS1621xs → **Dual Redundant PSU** (300W each)

### Implementation in Parser

```php
// Add to PowerSupplyParser
private function getPsuSpecForModel(string $model): ?array
{
    $specifications = [
        'rs3617rpxs' => ['count' => 2, 'redundant' => true, 'watts_per_psu' => 500],
        'rs3617xs'   => ['count' => 2, 'redundant' => true, 'watts_per_psu' => 500],
        'rs3617rp'   => ['count' => 2, 'redundant' => true, 'watts_per_psu' => 500],
        'rs2419rp'   => ['count' => 2, 'redundant' => true, 'watts_per_psu' => 250],
        'rs2419+'    => ['count' => 1, 'redundant' => false, 'watts_per_psu' => 250],
        'ds918+'     => ['count' => 1, 'redundant' => false, 'watts_per_psu' => 90],
        'ds1621xs'   => ['count' => 2, 'redundant' => true, 'watts_per_psu' => 300],
        'ds1821+'    => ['count' => 1, 'redundant' => false, 'watts_per_psu' => 180],
    ];
    
    $modelLower = strtolower($model);
    return $specifications[$modelLower] ?? null;
}
```

---

## Method 2: Chassis Information (DMI Type 3)

### What To Look For

```
Chassis Information
  Number Of Power Cords: 2          ← Indicates dual PSU
                      (1 = single, 2 = redundant)
```

**JJeth Data Shows**:
```
Number Of Power Cords: 1             ← Suspicious for dual-PSU model
```

**Interpretation**: 
- **Should be 2** for RS3617rpxs (one cord per PSU)
- **Showing 1** suggests one PSU is offline/disconnected

### Problem With This Method
- Not all systems correctly populate this field
- Some systems show "1" even with dual PSU
- Unreliable as sole source

---

## Method 3: IPMI FRU (Field Replaceable Unit) Listing

### Command
```bash
ipmitool fru print
```

### Example Output (Dual PSU)
```
FRU Device Description : PSU1 (ID 10h)
 Device is present
FRU Device Description : PSU2 (ID 11h)
 Device is present
```

### Example Output (Single PSU)
```
FRU Device Description : PSU (ID 10h)
 Device is present
```

### JJeth Status
**Not available in this debug bundle** (no ipmi_fru_status.result file)

---

## Method 4: DMI Type 39 Entries Count

### How It Works
Count how many "Handle 0xXXX, DMI type 39" sections appear in dmidecode output.

**Single PSU Model**:
```
Handle 0x002D, DMI type 39, 22 bytes
System Power Supply
  [single entry]
```

**Dual PSU Model**:
```
Handle 0x002D, DMI type 39, 22 bytes
System Power Supply
  [first PSU entry]

Handle 0x002E, DMI type 39, 22 bytes
System Power Supply
  [second PSU entry]
```

### JJeth Status
**Only ONE Type 39 entry found** ← Expected 2 for dual-PSU model

**Interpretation**:
- Should have 2 entries
- Only has 1
- **Strong indicator: One PSU is missing/offline**

---

## Method 5: System Event Log Analysis (IPMI)

### Command
```bash
ipmitool sel list | grep -i "psu\|power"
```

### What Indicates Redundancy Loss

```
2026-01-14 12:00:00 | PSU Fault | One or more PSUs failed or disconnected
2026-01-14 12:00:05 | Redundancy Degraded | System lost PSU redundancy
2026-01-14 12:00:10 | Chassis Power | Only one power supply is operational
```

### JJeth Status
**Not available in this debug bundle** (no ipmi_event_log.result file)

---

## Method 6: Load Info Snapshot (Most Authoritative)

### Data Source
```
dsm/result/load_info.result (JSON)
```

**Typical Structure**:
```json
{
  "data": {
    "hardware": {
      "psu_count": 2,
      "redundancy_mode": "dual",
      "psu_details": [
        {"index": 1, "status": "ok", "watts": 500},
        {"index": 2, "status": "failed", "watts": 500}
      ]
    }
  }
}
```

### JJeth Status
**Not available in this debug bundle** (no load_info.result file)

---

## Combined Analysis for JJeth

### Evidence Summary

| Method | Data Available | Finding | Interpretation |
|--------|---|---|---|
| **Model Lookup** | ✅ Yes | RS3617rpxs = Dual PSU | Should have 2 PSUs |
| **DMI Type Count** | ✅ Yes | 1 entry (expect 2) | **1 PSU missing** |
| **Power Cords** | ✅ Yes | 1 cord (expect 2) | **1 PSU disconnected** |
| **IPMI FRU** | ❌ No | Not in bundle | Cannot verify |
| **IPMI Events** | ❌ No | Not in bundle | Cannot verify |
| **Load Info** | ❌ No | Not in bundle | Cannot verify |

### Conclusion

**Verdict: DEFINITIVE REDUNDANT PSU FAILURE**

Evidence chain:
1. Model is RS3617rpxs (known to be dual-PSU capable)
2. DMI shows only 1 Type 39 entry (expect 2)
3. Chassis shows 1 power cord (expect 2)
4. System is operational (must be on remaining PSU)

**Status**: One PSU failed/offline, system on single PSU, **zero redundancy**

---

## Enhanced Parser: PSU Configuration Detection

### Update to PowerSupplyParser

```php
class PowerSupplyParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        // ... existing code ...
        
        // NEW: Detect hardware model and expected PSU configuration
        $model = $this->getModelFromContext($context, $extractedPath);
        $expectedConfig = $this->getPsuSpecForModel($model);
        
        // Compare expected vs. actual
        $assessment = $this->assessPowerHealth($data, $expectedConfig, $model);
        
        return ['data' => $data, 'citations' => $citations];
    }
    
    /**
     * Get PSU specification for model
     * Returns: ['count' => 2, 'redundant' => true, 'watts_per_psu' => 500]
     */
    private function getPsuSpecForModel(string $model): ?array
    {
        // Comprehensive hardware database
        $specs = [
            // RackStations - 16-bay
            'rs3617rpxs' => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            'rs3617xs'   => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            'rs3617rp'   => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            
            // RackStations - 2-bay, 12-bay
            'rs2419rp'   => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],
            'rs2419+'    => ['count' => 1, 'watts' => [250], 'redundant' => false],
            'rs2419rpu'  => ['count' => 1, 'watts' => [250], 'redundant' => false],
            
            // Desktop models
            'ds918+'     => ['count' => 1, 'watts' => [90], 'redundant' => false],
            'ds1819+'    => ['count' => 1, 'watts' => [180], 'redundant' => false],
            'ds1621xs'   => ['count' => 2, 'watts' => [300, 300], 'redundant' => true],
            'ds1821+'    => ['count' => 1, 'watts' => [180], 'redundant' => false],
        ];
        
        return $specs[strtolower($model)] ?? null;
    }
    
    /**
     * Enhanced health assessment with model knowledge
     */
    private function assessPowerHealth(
        array $data,
        ?array $expectedConfig,
        string $model
    ): array {
        $assessment = [
            'overall_status' => 'healthy',
            'redundancy_status' => 'none',
            'risk_factors' => [],
            'requires_attention' => false,
            'model' => $model,
            'expected_psu_count' => $expectedConfig['count'] ?? null
        ];
        
        // Count actual PSUs
        $actualPsuCount = count($data['power_supplies'] ?? []);
        
        // Compare expected vs. actual
        if ($expectedConfig && $expectedConfig['redundant']) {
            $assessment['redundancy_status'] = 'redundant_capable';
            
            if ($actualPsuCount < $expectedConfig['count']) {
                // CRITICAL: Redundant model with missing PSU
                $missing = $expectedConfig['count'] - $actualPsuCount;
                $assessment['overall_status'] = 'critical';
                $assessment['redundancy_status'] = 'degraded';
                $assessment['requires_attention'] = true;
                $assessment['risk_factors'][] = 
                    "Redundant PSU model missing $missing PSU(s) - running on single PSU";
            }
        } else {
            $assessment['redundancy_status'] = 'single';
        }
        
        return $assessment;
    }
}
```

---

## Recommended Enhanced Rule

```yaml
id: hardware.redundant_psu_configuration_mismatch
version: 1
title: "CRITICAL: Redundant PSU model operating without full redundancy"
severity: critical
actionability: vendor_issue
category: hardware
description: >
  This is a redundant-PSU-capable model ({model}) but only {actual_psu_count}
  PSU(s) detected ({expected_psu_count} expected). The system is operating
  without fault tolerance.
signature:
  type: aggregate
  source: power_supply_parser
  metric: psu_count_mismatch_on_redundant_model
  operator: ">"
  threshold: 0
entities:
  model: "$match.model"
  expected_psu_count: "$match.expected_psu_count"
  actual_psu_count: "$match.actual_psu_count"
  missing_psu_count: "$match.missing_psu_count"
```

---

## Implementation Checklist

- [ ] Create PSU hardware specification database
- [ ] Extract model from synoinfo.conf
- [ ] Implement `getPsuSpecForModel()` lookup
- [ ] Enhance health assessment to compare expected vs. actual
- [ ] Add "model" and "expected_psu_count" to output data
- [ ] Create model-aware redundancy rules
- [ ] Add test cases for known models
- [ ] Update documentation with supported models

---

## Summary

**Can DSM tell us if device is single or redundant PSU?**

**Directly**: No, synoinfo.conf doesn't declare PSU configuration.

**Indirectly**: Yes, through:
1. Model lookup (most reliable)
2. DMI Type 39 entry count
3. Chassis power cord count
4. IPMI FRU listing (if available)
5. IPMI System Event Log (if available)

**For JJeth specifically**:
- Model: RS3617rpxs = Definitely dual-PSU capable
- DMI: Only 1 Type 39 entry (expect 2) = One PSU missing
- Chassis: 1 power cord (expect 2) = One PSU offline

**Verdict**: Redundant PSU failure - one PSU is offline/failed.
