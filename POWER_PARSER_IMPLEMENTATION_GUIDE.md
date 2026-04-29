# Power Supply Parser - Implementation Guide

## Quick Integration (30 minutes)

This guide provides exact code changes needed to activate PowerSupplyParser in the DeepDive pipeline.

---

## Step 1: Register Parser in ParseStep (5 minutes)

**File**: `src/DeepDive/Pipeline/ParseStep.php`

### Change 1.1: Add import (Line ~11)

**Before**:
```php
use App\DeepDive\Hardware\HardwareSpecExtractor;
use App\DeepDive\Parsers\BundleLocator;
use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Rules\Sources\SourceRegistry;
```

**After**:
```php
use App\DeepDive\Hardware\HardwareSpecExtractor;
use App\Parsers\PowerSupplyParser;
use App\DeepDive\Parsers\BundleLocator;
use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Rules\Sources\SourceRegistry;
```

### Change 1.2: Instantiate parser (Line ~100)

**Before**:
```php
$year     = (int)date('Y');
$locator  = new BundleLocator(new TimestampParser($year));
$registry = new SourceRegistry();
$facts    = [];
$hwExtractor = new HardwareSpecExtractor();
```

**After**:
```php
$year     = (int)date('Y');
$locator  = new BundleLocator(new TimestampParser($year));
$registry = new SourceRegistry();
$facts    = [];
$hwExtractor = new HardwareSpecExtractor();
$powerParser = new PowerSupplyParser();
```

### Change 1.3: Call parser in bundle loop (Around line ~120-150)

**Find** this section in ParseStep.php:
```php
// For each bundle:
foreach ($bundles as $bundlePath) {
    // ... existing code for location, hardware, facts ...
    $locator->locate($bundlePath, $registry);
    $hardware = $hwExtractor->extract($bundlePath);
    // ... store hardware in facts ...
}
```

**Add this code** after hardware extraction:
```php
// Extract power supply information
try {
    $powerData = $powerParser->parse($bundlePath, [
        'hardware' => $hardware,
        'majorversion' => $context['dsm_version'] ?? 7
    ]);
    
    // Store in context for RenderStep
    if (!isset($ctx->bag['power_data'])) {
        $ctx->bag['power_data'] = [];
    }
    $ctx->bag['power_data'][] = [
        'bundle' => basename($bundlePath),
        'data' => $powerData['data'],
        'citations' => $powerData['citations'] ?? []
    ];
} catch (Exception $e) {
    // Log error but continue - power data is supplementary
    error_log("PowerSupplyParser error for {$bundlePath}: " . $e->getMessage());
}
```

---

## Step 2: Add to RenderStep (5 minutes)

**File**: `src/DeepDive/Pipeline/RenderStep.php`

### Change 2.1: Pass power data to renderer

**Find** where ReportRenderer is instantiated:
```php
$renderer = new ReportRenderer($bundles, $facts, ...);
```

**Modify** to pass power data:
```php
$renderer = new ReportRenderer(
    $bundles, 
    $facts, 
    $ctx->bag['power_data'] ?? [],  // Add this
    ...
);
```

---

## Step 3: Update ReportRenderer (15 minutes)

**File**: `src/DeepDive/Report/ReportRenderer.php`

### Change 3.1: Add constructor parameter

**Find** the ReportRenderer constructor:
```php
public function __construct(array $bundles, array $facts, ...)
```

**Modify**:
```php
public function __construct(
    array $bundles, 
    array $facts,
    array $powerData = [],  // Add this parameter
    ...
)
{
    $this->bundles = $bundles;
    $this->facts = $facts;
    $this->powerData = $powerData;  // Store it
    // ... rest of constructor ...
}
```

### Change 3.2: Add power data property

**Add** to class properties (top of class):
```php
private array $powerData = [];
```

### Change 3.3: Add rendering method

**Add** this method to ReportRenderer class:
```php
/**
 * Render power supply findings section
 */
private function renderPowerSection(): string
{
    if (empty($this->powerData)) {
        return '';
    }

    $html = '<section class="power-analysis">';
    $html .= '<h2>Power Supply Analysis</h2>';

    foreach ($this->powerData as $powerInfo) {
        $data = $powerInfo['data'] ?? [];
        $assessment = $data['health_assessment'] ?? [];
        
        $html .= '<div class="power-bundle">';
        $html .= '<h3>' . htmlspecialchars($powerInfo['bundle']) . '</h3>';
        
        // Health Status
        $status = $assessment['overall_status'] ?? 'unknown';
        $statusClass = match($status) {
            'critical' => 'status-critical',
            'warning' => 'status-warning',
            'caution' => 'status-caution',
            default => 'status-healthy'
        };
        
        $html .= '<div class="' . $statusClass . '">';
        $html .= '<strong>Status:</strong> ' . htmlspecialchars(ucfirst($status));
        $html .= '</div>';
        
        // Power Supplies
        if (!empty($data['power_supplies'])) {
            $html .= '<div class="power-supplies">';
            $html .= '<h4>Power Supplies</h4>';
            $html .= '<table class="psu-table">';
            $html .= '<tr><th>Index</th><th>Status</th><th>Detection</th><th>Plugged</th><th>Capacity</th></tr>';
            
            foreach ($data['power_supplies'] as $psu) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars((string)$psu['index']) . '</td>';
                $html .= '<td>' . htmlspecialchars($psu['status'] ?? 'unknown') . '</td>';
                $html .= '<td>' . htmlspecialchars($psu['detection_status'] ?? 'unknown') . '</td>';
                $html .= '<td>' . ($psu['plugged'] ? 'Yes' : 'No') . '</td>';
                $html .= '<td>' . htmlspecialchars((string)($psu['max_capacity_watts'] ?? 'N/A')) . 'W</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
        
        // Risk Factors
        if (!empty($assessment['risk_factors'])) {
            $html .= '<div class="risk-factors">';
            $html .= '<h4>Risk Factors</h4>';
            $html .= '<ul>';
            foreach ($assessment['risk_factors'] as $factor) {
                $html .= '<li>' . htmlspecialchars($factor) . '</li>';
            }
            $html .= '</ul>';
            $html .= '</div>';
        }
        
        // Voltage Readings
        if (!empty($data['voltage_readings'])) {
            $html .= '<div class="voltage-readings">';
            $html .= '<h4>Voltage Readings</h4>';
            $html .= '<table class="voltage-table">';
            $html .= '<tr><th>Rail</th><th>Voltage</th><th>Status</th></tr>';
            
            foreach ($data['voltage_readings'] as $reading) {
                $html .= '<tr>';
                $html .= '<td>' . htmlspecialchars($reading['rail_name'] ?? 'Unknown') . '</td>';
                $html .= '<td>' . number_format($reading['voltage_volts'] ?? 0, 2) . 'V</td>';
                $html .= '<td>' . htmlspecialchars($reading['status'] ?? 'unknown') . '</td>';
                $html .= '</tr>';
            }
            
            $html .= '</table>';
            $html .= '</div>';
        }
        
        $html .= '</div>';
    }

    $html .= '</section>';
    return $html;
}
```

### Change 3.4: Include in main render output

**Find** the main render() method where sections are assembled:
```php
public function render(): string
{
    $html = '...';
    $html .= $this->renderHardware();
    $html .= $this->renderDrives();
    // ... other sections ...
    return $html;
}
```

**Add**:
```php
$html .= $this->renderPowerSection();
```

---

## Step 4: Add CSS Styling (5 minutes)

**File**: CSS file (your main stylesheet)

**Add**:
```css
/* Power Supply Analysis */
.power-analysis {
    margin: 2rem 0;
    padding: 1.5rem;
    border: 1px solid #ddd;
    border-radius: 8px;
}

.power-analysis h2 {
    margin-top: 0;
    color: #333;
}

.power-bundle {
    margin-bottom: 1.5rem;
    padding-bottom: 1.5rem;
    border-bottom: 1px solid #eee;
}

.power-bundle:last-child {
    border-bottom: none;
}

.status-critical {
    background-color: #fee;
    color: #c00;
    padding: 0.5rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.status-warning {
    background-color: #ffd;
    color: #880;
    padding: 0.5rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.status-caution {
    background-color: #eef;
    color: #088;
    padding: 0.5rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.status-healthy {
    background-color: #efe;
    color: #080;
    padding: 0.5rem;
    border-radius: 4px;
    margin-bottom: 1rem;
}

.power-supplies,
.voltage-readings,
.risk-factors {
    margin-top: 1rem;
}

.psu-table,
.voltage-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9rem;
    margin-top: 0.5rem;
}

.psu-table th,
.voltage-table th {
    background-color: #f5f5f5;
    padding: 0.5rem;
    text-align: left;
    border-bottom: 2px solid #ddd;
}

.psu-table td,
.voltage-table td {
    padding: 0.5rem;
    border-bottom: 1px solid #eee;
}

.risk-factors ul {
    list-style-type: none;
    padding-left: 0;
}

.risk-factors li {
    padding: 0.5rem 0;
    padding-left: 1.5rem;
    position: relative;
}

.risk-factors li:before {
    content: "⚠️ ";
    position: absolute;
    left: 0;
}
```

---

## Step 5: Verify Rules are Loaded (2 minutes)

**Files** (should already exist):
- ✅ `config/deepdive/rules/hardware/psu_not_detected.yaml`
- ✅ `config/deepdive/rules/hardware/redundant_psu_failure.yaml`
- ✅ `config/deepdive/rules/hardware/voltage_instability.yaml`
- ✅ `config/deepdive/rules/hardware/power_anomaly_detected.yaml`

**Check** if your RuleCatalog auto-discovers YAML files in `hardware/` directory. If not, register explicitly:

```php
// In RuleCatalog or wherever rules are registered
$catalog->load('config/deepdive/rules/hardware/psu_not_detected.yaml');
$catalog->load('config/deepdive/rules/hardware/redundant_psu_failure.yaml');
$catalog->load('config/deepdive/rules/hardware/voltage_instability.yaml');
$catalog->load('config/deepdive/rules/hardware/power_anomaly_detected.yaml');
```

---

## Testing (10 minutes)

### Test 1: With JJeth Data

```bash
# Run parser directly on JJeth debug bundle
php -r '
$parser = new \App\Parsers\PowerSupplyParser();
$result = $parser->parse("./sample/JJeth/dsm", ["majorversion" => 7]);
echo "PSU Count: " . count($result["data"]["power_supplies"]) . "\n";
echo "Health Status: " . $result["data"]["health_assessment"]["overall_status"] . "\n";
var_dump($result["data"]["health_assessment"]);
'
```

**Expected Output**:
```
PSU Count: 1
Health Status: critical
array(8) {
  ["overall_status"]=> string(8) "critical"
  ["redundancy_status"]=> string(8) "degraded"
  ["actual_psu_count"]=> int(1)
  ["expected_psu_count"]=> int(2)
  ["redundancy_capable"]=> bool(true)
  ["requires_attention"]=> bool(true)
  ["risk_factors"]=> array(1) {
    [0]=> string(98) "CRITICAL: Redundant PSU model missing 1 PSU(s) - running on single PSU with zero fault tolerance"
  }
}
```

### Test 2: Full Pipeline

```bash
# Run full DeepDive analysis
# (Assuming your CLI tool is available)
deepdive analyze ./sample/JJeth/dsm

# Check HTML output for power section
grep -A 20 "power-analysis" output.html
```

### Test 3: Rule Firing

```bash
# Verify rules fire correctly
# Check that:
# 1. hardware.redundant_psu_failure rule fires (HIGH severity)
# 2. hardware.psu_not_detected rule fires (CRITICAL severity)
# 3. Findings appear in report
```

---

## Validation Checklist

- [ ] PowerSupplyParser import added to ParseStep.php
- [ ] Parser instantiated in ParseStep
- [ ] parse() called for each bundle
- [ ] Results stored in $ctx->bag['power_data']
- [ ] RenderStep updated to pass power data
- [ ] ReportRenderer constructor accepts power data
- [ ] renderPowerSection() method added
- [ ] Method called in main render() flow
- [ ] CSS styling added
- [ ] All 4 rule YAML files present in config/deepdive/rules/hardware/
- [ ] Test passes with JJeth data
- [ ] HTML output includes power section
- [ ] Rules fire correctly
- [ ] Status colors display correctly

---

## Common Issues

### Issue: "PowerSupplyParser not found"
**Solution**: Verify import statement: `use App\Parsers\PowerSupplyParser;`

### Issue: "No power data in output"
**Solution**: 
1. Check $ctx->bag['power_data'] is populated
2. Verify RenderStep receives updated RenderStep
3. Check renderPowerSection() is called in main render()

### Issue: "Rules not firing"
**Solution**:
1. Verify YAML files are in correct location
2. Check RuleCatalog loads hardware/*.yaml files
3. Verify PowerSupplyParser data is being registered as evidence

### Issue: "HTML looks broken"
**Solution**:
1. Check CSS is loaded
2. Verify htmlspecialchars() is escaping output correctly
3. Check table structure is valid HTML

---

## Summary

**Total implementation time**: ~30 minutes

**Changes required**:
- ParseStep.php: 3 changes (import, instantiate, call)
- RenderStep.php: 1 change (pass power data)
- ReportRenderer.php: 4 changes (constructor, property, method, call)
- CSS: Add power section styling
- Rules: Already created (4 YAML files)

**Result**: Power supply issues now detected and reported before they cause data loss.

---

## Next Steps (Optional Enhancement)

1. **Add Unit Tests**
   - Test parser with various model configurations
   - Test health assessment logic
   - Test rule firing

2. **Add Monitoring Dashboard**
   - Display PSU status in real-time
   - Track voltage trends
   - Alert on degradation

3. **Add Historical Tracking**
   - Store PSU data over time
   - Detect aging patterns
   - Predict failure timing

4. **Fleet Analytics**
   - Correlate PSU failures across devices
   - Identify failure patterns by model
   - Recommend preventive maintenance
