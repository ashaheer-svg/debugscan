<?php
/**
 * Test PowerSupplyParser with JJeth bundle
 *
 * Usage: php public/test-powersupply.php /path/to/extracted/bundle
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Parsers\PowerSupplyParser;

$extractedPath = $argv[1] ?? null;

if (!$extractedPath) {
    echo "Usage: php public/test-powersupply.php /path/to/extracted/bundle\n";
    exit(1);
}

if (!is_dir($extractedPath)) {
    echo "Error: Path does not exist: $extractedPath\n";
    exit(1);
}

try {
    echo "=== PowerSupplyParser Test ===\n\n";
    echo "Extracted Path: $extractedPath\n";

    // Check if dmidecode.result exists
    $dmiFile = $extractedPath . '/result/dmidecode.result';
    if (file_exists($dmiFile)) {
        echo "✓ Found dmidecode.result\n";
    } else {
        echo "✗ dmidecode.result NOT found\n";
    }

    // Run the parser
    $parser = new PowerSupplyParser();
    $result = $parser->parse($extractedPath, [
        'hardware' => ['model' => 'RS3617rpxs'],
        'majorversion' => 7
    ]);

    echo "\n=== POWER SUPPLY PARSER RESULT ===\n\n";

    // Display power supplies
    if (!empty($result['data']['power_supplies'])) {
        echo "Power Supplies Found: " . count($result['data']['power_supplies']) . "\n\n";

        foreach ($result['data']['power_supplies'] as $psu) {
            echo "PSU #" . $psu['index'] . ":\n";
            echo "  Status: " . ($psu['status'] ?? 'unknown') . "\n";
            echo "  Detection: " . ($psu['detection_status'] ?? 'unknown') . "\n";
            echo "  Plugged: " . ($psu['plugged'] === true ? 'Yes' : ($psu['plugged'] === false ? 'No' : 'Unknown')) . "\n";
            echo "  Manufacturer: " . ($psu['manufacturer'] ?? 'N/A') . "\n";
            echo "  Model: " . ($psu['model'] ?? 'N/A') . "\n";
            echo "  Serial: " . ($psu['serial'] ?? 'N/A') . "\n";
            echo "  Max Capacity: " . ($psu['max_capacity_watts'] ?? 'N/A') . " W\n";
            echo "\n";
        }
    } else {
        echo "No power supplies found in dmidecode\n\n";
    }

    // Display voltage readings
    if (!empty($result['data']['voltage_readings'])) {
        echo "Voltage Readings: " . count($result['data']['voltage_readings']) . "\n\n";
        foreach ($result['data']['voltage_readings'] as $voltage) {
            echo "  " . $voltage['rail_name'] . ": " . $voltage['voltage_volts'] . "V (" . $voltage['status'] . ")\n";
        }
        echo "\n";
    }

    // Display health assessment
    if (!empty($result['data']['health_assessment'])) {
        $health = $result['data']['health_assessment'];
        echo "Health Assessment:\n";
        echo "  Overall Status: " . ($health['overall_status'] ?? 'unknown') . "\n";
        echo "  Assessment Status: " . ($health['assessment_status'] ?? 'based_on_data') . "\n";
        echo "  Has Power Data: " . ($health['has_power_data'] ? 'Yes' : 'No') . "\n";
        echo "  Redundancy Status: " . ($health['redundancy_status'] ?? 'none') . "\n";
        echo "  Requires Attention: " . ($health['requires_attention'] ? 'YES' : 'No') . "\n";

        if (!empty($health['risk_factors'])) {
            echo "  Risk Factors:\n";
            foreach ($health['risk_factors'] as $factor) {
                echo "    - " . $factor . "\n";
            }
        }
        echo "\n";
    }

    // Display citations
    if (!empty($result['citations'])) {
        echo "Citations: " . count($result['citations']) . " file(s) analyzed\n";
        foreach ($result['citations'] as $citation) {
            echo "  - " . $citation['file'] . "\n";
        }
    }

    echo "\n=== EXPECTED ANOMALY DETECTION ===\n\n";

    $health = $result['data']['health_assessment'] ?? [];
    $status = $health['overall_status'] ?? 'unknown';

    if ($status === 'critical') {
        echo "✓ CRITICAL STATUS DETECTED\n";
        echo "  PowerSupplyParser found a critical issue!\n";
        echo "  AnomalyDetector will create: PSU_HEALTH_CRITICAL anomaly\n";
        echo "  Report will show: Anomalies > 0, Risk Level: CRITICAL\n";
    } elseif ($status === 'warning') {
        echo "⚠ WARNING STATUS DETECTED\n";
        echo "  PowerSupplyParser found a warning condition\n";
        echo "  AnomalyDetector will create: PSU_HEALTH_WARNING anomaly\n";
        echo "  Report will show: Anomalies > 0, Risk Level: HIGH\n";
    } elseif ($status === 'caution') {
        $assessStatus = $health['assessment_status'] ?? 'based_on_data';
        if ($assessStatus === 'insufficient_data') {
            echo "⚠ CAUTION (INSUFFICIENT DATA)\n";
            echo "  No power supply data available to assess\n";
            echo "  AnomalyDetector will SKIP this (not a real anomaly)\n";
            echo "  Report will show: Anomalies: 0 (correct)\n";
        } else {
            echo "⚠ CAUTION STATUS DETECTED\n";
            echo "  PowerSupplyParser found a caution condition\n";
            echo "  AnomalyDetector will create: PSU_HEALTH_CAUTION anomaly\n";
            echo "  Report will show: Anomalies > 0, Risk Level: MEDIUM\n";
        }
    } else {
        echo "✓ HEALTHY STATUS\n";
        echo "  No power supply issues detected\n";
        echo "  AnomalyDetector will NOT create anomalies\n";
        echo "  Report will show: Anomalies: 0 (correct)\n";
    }

    echo "\n=== RAW JSON OUTPUT ===\n\n";
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "\nStack Trace:\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
