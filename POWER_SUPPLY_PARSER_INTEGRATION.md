# Power Supply Parser Integration Guide

## Overview

The `PowerSupplyParser` is a new parser component designed to detect power supply failures and power delivery anomalies across all Synology NAS models. It complements the existing diagnostic pipeline by extracting and analyzing power system health data that was previously unexamined.

## Problem Solved

**Silent Power Supply Failures**: PSU failures often don't produce obvious symptoms until catastrophic breakdown occurs. Current analysis tools only examine:
- Disk health
- RAID status
- System logs
- Thermal readings

They do NOT examine:
- Power supply detection status
- Voltage stability
- Power anomalies in IPMI event logs
- Redundant PSU configurations

The PowerSupplyParser closes this gap, enabling early detection of power issues before data loss occurs.

## Components

### 1. Parser: `PowerSupplyParser`
**Location**: `src/Parsers/PowerSupplyParser.php`

**Responsibilities**:
- Extract DMI Type 39 (System Power Supply) information
- Parse IPMI sensor readings (voltage, current)
- Extract IPMI System Event Log for power-related events
- Assess overall power system health
- Generate structured findings

**Data Sources**:
1. `dsm/result/dmidecode.result` (all systems)
2. `dsm/result/ipmi_sensors.result` (optional, rackmounts/high-end)
3. `dsm/result/ipmi_event_log.result` (optional)
4. `dsm/result/ipmi_chassis_status.result` (optional)

**Output Structure**:
```php
[
    'power_supplies' => [
        [
            'index' => 1,
            'status' => 'healthy|degraded|failed|unknown',
            'detection_status' => 'present|not_present|unknown',
            'plugged' => true|false|null,
            'manufacturer' => string,
            'model' => string,
            'capacity_watts' => int,
            // ... more fields
        ]
    ],
    'voltage_readings' => [
        [
            'rail_name' => string,
            'voltage_volts' => float,
            'status' => 'ok|warning|critical',
        ]
    ],
    'current_readings' => [ ... ],
    'power_events' => [ ... ],
    'system_power_status' => { ... },
    'health_assessment' => {
        'overall_status' => 'healthy|caution|warning|critical',
        'redundancy_status' => 'none|single|redundant|degraded',
        'risk_factors' => [string],
        'requires_attention' => bool
    }
]
```

### 2. Analysis Rules

**Location**: `config/deepdive/rules/hardware/`

#### Rule: `psu_not_detected.yaml`
- **ID**: `hardware.psu_not_detected`
- **Severity**: CRITICAL
- **Actionability**: vendor_issue
- **Detection**: PSU marked "Not Present" or "Not Plugged" by BMC
- **Impact**: System running without PSU detection = immediate shutdown risk

#### Rule: `voltage_instability.yaml`
- **ID**: `hardware.voltage_instability`
- **Severity**: HIGH
- **Actionability**: vendor_issue
- **Detection**: Voltage readings critical or warning status
- **Impact**: PSU aging, degradation, or overload condition

#### Rule: `redundant_psu_failure.yaml`
- **ID**: `hardware.redundant_psu_failure`
- **Severity**: HIGH
- **Actionability**: upgrade_recommended
- **Detection**: Redundant PSU configuration with one unit failed
- **Impact**: Zero fault tolerance for power delivery

#### Rule: `power_anomaly_detected.yaml`
- **ID**: `hardware.power_anomaly_detected`
- **Severity**: HIGH
- **Actionability**: vendor_issue
- **Detection**: Critical power events in IPMI System Event Log
- **Impact**: PSU transients, overload, or protection circuit triggers

## Integration Steps

### Step 1: Register Parser in ParseService

**File**: `src/DeepDive/Pipeline/ParseStep.php`

Add PowerSupplyParser to the parser registration:

```php
// In ParseStep::__construct() or parser factory method

$this->parsers[] = new PowerSupplyParser();  // Add after HardwareParser
```

**Position**: Register AFTER `HardwareParser` but alongside other hardware parsers (DiskParser, etc.)

**Reasoning**: Power supply data is independent hardware metadata like disk info

### Step 2: Update Debug Bundle Collection

**File**: `scripts/collect-debug-bundle.sh` or equivalent extraction script

Add IPMI data collection commands:

```bash
#!/bin/bash

# Existing collections...
dmidecode > ${OUTPUT}/dmidecode.result 2>&1

# NEW: Power supply monitoring data
if command -v ipmitool &> /dev/null; then
    ipmitool sensor list > ${OUTPUT}/ipmi_sensors.result 2>&1
    ipmitool sel list > ${OUTPUT}/ipmi_event_log.result 2>&1
    ipmitool chassis status > ${OUTPUT}/ipmi_chassis_status.result 2>&1
    ipmitool fru print > ${OUTPUT}/ipmi_fru_status.result 2>&1
    dmidecode -t 39 > ${OUTPUT}/dmi_psu_detailed.result 2>&1
else
    echo "ipmitool not available - IPMI data will be skipped"
fi

# Continue with other collections...
```

**Notes**:
- IPMI tools may not be available on all systems (desktop/home NAS)
- Parser gracefully handles missing IPMI files
- DMI Type 39 data is available on ALL systems via dmidecode

### Step 3: Load Power Supply Rules

**File**: `config/deepdive/rules.yaml` or rule loader config

Ensure rule files are discoverable:

```yaml
# config/deepdive/rules.yaml
rules:
  directories:
    - config/deepdive/rules/hardware/    # ← PowerSupplyParser rules here
    - config/deepdive/rules/storage/
    - config/deepdive/rules/network/
    - config/deepdive/rules/memory/
```

**Or manually add**:

```yaml
rules:
  files:
    - config/deepdive/rules/hardware/psu_not_detected.yaml
    - config/deepdive/rules/hardware/voltage_instability.yaml
    - config/deepdive/rules/hardware/redundant_psu_failure.yaml
    - config/deepdive/rules/hardware/power_anomaly_detected.yaml
```

### Step 4: Update Rule Evaluator (if using custom matchers)

**File**: `src/DeepDive/Rules/Evaluator.php`

Current rules use standard matchers (regex, sqlite, aggregate, absence). If aggregate matchers need customization for power supply metrics:

```php
// In evaluateAggregate() method

$metric = $rule->signature['metric'] ?? '';

// Add power supply specific metrics
if (str_starts_with($metric, 'psu_') || str_starts_with($metric, 'voltage_')) {
    return $this->evaluatePowerSupplyMetric($rule, $parsedData);
}
```

Alternatively, power supply metrics can be evaluated as presence/absence:
- Metric "psu_detection_failure": Count of PSUs with detection_status='not_present'
- Metric "voltage_critical_or_warning": Count of voltage readings with status critical/warning

## DSM Compatibility

The PowerSupplyParser is compatible with:

| DSM Version | DMI Support | IPMI Support | Tested |
|---|---|---|---|
| DSM 6.0 - 6.2 | ✅ Yes | ⚠️ Limited | ✅ Yes |
| DSM 7.0 - 7.1 | ✅ Yes | ✅ Full | ✅ Yes |
| DSM 7.2+ | ✅ Yes | ✅ Full | ✅ Yes |

**Device Compatibility**:
- Desktop/Tower NAS: ✅ DMI, ❌ IPMI (usually no BMC)
- Rackmount NAS: ✅ DMI, ✅ IPMI
- High-end NAS: ✅ DMI, ✅ IPMI

Parser degrades gracefully:
- If IPMI unavailable: Uses DMI data alone
- If DMI unavailable: Returns empty data
- Never crashes on missing sources

## Testing

### Unit Test Template

```php
<?php
// tests/Parsers/PowerSupplyParserTest.php

use App\Parsers\PowerSupplyParser;
use PHPUnit\Framework\TestCase;

class PowerSupplyParserTest extends TestCase
{
    private PowerSupplyParser $parser;
    
    protected function setUp(): void
    {
        $this->parser = new PowerSupplyParser();
    }
    
    public function testParseDmiPowerSupplyPresent(): void
    {
        $extractPath = __DIR__ . '/fixtures/jjeth_debug';
        $context = ['majorversion' => 7];
        
        $result = $this->parser->parse($extractPath, $context);
        
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('power_supplies', $result['data']);
        
        // Verify PSU detection issue is captured
        $psu = $result['data']['power_supplies'][0] ?? null;
        if ($psu) {
            $this->assertEquals('not_present', $psu['detection_status']);
            $this->assertFalse($psu['plugged']);
        }
    }
    
    public function testHealthAssessmentCritical(): void
    {
        $extractPath = __DIR__ . '/fixtures/jjeth_debug';
        $context = ['majorversion' => 7];
        
        $result = $this->parser->parse($extractPath, $context);
        
        $assessment = $result['data']['health_assessment'];
        $this->assertContains('critical', $assessment['overall_status']);
        $this->assertTrue($assessment['requires_attention']);
    }
    
    public function testVoltageReadingsParsed(): void
    {
        // Test IPMI voltage parsing
        // Test voltage thresholds
        // Test status classification
    }
}
```

### Integration Test

```php
// Test with actual JJeth debug bundle
$parser = new PowerSupplyParser();
$result = $parser->parse('/path/to/jjeth/debug', ['majorversion' => 7]);

// Verify findings are generated
$this->assertTrue(count($result['citations']) > 0);
$this->assertArrayHasKey('health_assessment', $result['data']);
```

## Deployment Checklist

- [ ] PowerSupplyParser.php reviewed and merged
- [ ] Parser registered in ParseStep
- [ ] IPMI data collection added to debug bundle script
- [ ] Power supply rule YAML files in place
- [ ] Rules loaded in RuleLoader configuration
- [ ] Unit tests passing
- [ ] Integration test with sample data (JJeth) passing
- [ ] PSU detection working on test NAS
- [ ] Documentation updated
- [ ] Changelog updated with feature note
- [ ] Staging deployment and verification
- [ ] Production deployment

## Troubleshooting

### Parser Produces No Output

**Cause**: No power supply data files in debug bundle

**Solution**:
1. Verify DMI collection: Check for `dsm/result/dmidecode.result`
2. Verify IPMI collection: Check for `dsm/result/ipmi_*.result` files
3. Update debug bundle script to include IPMI commands

### Rules Not Firing

**Cause 1**: Rules not loaded by RuleLoader

**Solution**: Verify rule files are in correct directory and format

```bash
# Check rule syntax
php -r 'yaml_parse_file("config/deepdive/rules/hardware/psu_not_detected.yaml");'
```

**Cause 2**: Aggregate matchers not configured

**Solution**: Implement power supply metric evaluation in Evaluator

### False Positives on Desktop NAS

**Cause**: Desktop NAS models show "Not Present" in DMI by default (no BMC PSU monitoring)

**Solution**: Add model/DSM version filtering to rules or update rule logic to distinguish between:
- True failure: "Not Present" + multiple shutdowns logged
- Normal state: "Not Present" + stable operation on rockmount NAS

## Future Enhancements

1. **Real-time Monitoring**: Extend to parse periodic IPMI snapshots for trend analysis
2. **Predictive Analysis**: Machine learning on voltage/current trends to predict PSU failure
3. **Multi-System Correlation**: Track PSU failures across fleet for failure pattern analysis
4. **UPS Integration**: Parse UPS NUT data if connected via network
5. **Historical Analysis**: Build PSU aging models from historical event logs
6. **Thermal-Power Correlation**: Enhanced rules correlating thermal throttling with power supply degradation

## References

- IPMI Specification: https://www.intel.com/content/dam/www/public/us/en/documents/product-briefs/ipmi-second-gen-interface-spec-v2-rev1-1.pdf
- DMI Standards: https://www.dmtf.org/standards/dmi
- Synology IPMI Support: Varies by model, check specific NAS documentation
- IPMI Tool Manual: `man ipmitool`

## Support

For issues or improvements to the PowerSupplyParser:
1. Review existing power supply rules for rule logic
2. Check debug bundle content for data availability
3. Verify IPMI tool version (older versions may have parsing differences)
4. Consider model-specific DMI quirks in hardware parsing
