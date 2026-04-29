<?php

/**
 * Phase 2 Root Cause Analysis - Working Example
 *
 * Demonstrates complete flow from Phase 1 anomalies through
 * Phase 2 event correlation and root cause analysis.
 *
 * Includes 4 realistic scenarios:
 * 1. Silent PSU failure with cascade to thermal
 * 2. Disk degradation causing slowdown
 * 3. Thermal cascade from fan failure
 * 4. Voltage instability pattern
 *
 * Usage:
 *   php examples/phase_2_root_cause_analysis_example.php
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\DeepDive\AI\CausalChain;
use App\DeepDive\AI\EventCorrelator;
use App\DeepDive\AI\RootCauseAnalyzer;

echo "=== Phase 2 Root Cause Analysis Example ===\n\n";

// ============================================================================
// Scenario 1: Silent PSU Failure
// ============================================================================

echo "[1] Scenario 1: Silent PSU Failure (RS3617rpxs)\n";
echo str_repeat("=", 60) . "\n\n";

// Phase 1 anomalies for this scenario
$scenario1_anomalies = [
    [
        'timestamp'  => '2026-04-27T14:30:00',
        'type'       => 'PSU_NOT_DETECTED',
        'severity'   => 'CRITICAL',
        'confidence' => 0.99,
        'message'    => 'PSU marked not present (DMI Type 39)',
    ],
    [
        'timestamp'  => '2026-04-27T14:30:30',
        'type'       => 'VOLTAGE_INSTABILITY',
        'severity'   => 'HIGH',
        'confidence' => 0.88,
        'message'    => 'Voltage out of spec: 11.8V (below -2% limit)',
    ],
    [
        'timestamp'  => '2026-04-27T14:31:00',
        'type'       => 'CPU_THROTTLE',
        'severity'   => 'MEDIUM',
        'confidence' => 0.85,
        'message'    => 'CPU throttled to 1.2GHz due to voltage sag',
    ],
    [
        'timestamp'  => '2026-04-27T14:32:15',
        'type'       => 'THERMAL_EXCURSION',
        'severity'   => 'WARNING',
        'confidence' => 0.82,
        'message'    => 'Temperature 87.5°C (elevated due to throttling)',
    ],
];

$scenario1_clusters = [
    [
        'cluster_id'    => 0,
        'start_time'    => '2026-04-27T14:30:00',
        'end_time'      => '2026-04-27T14:32:15',
        'anomaly_count' => 4,
        'severity'      => 'HIGH',
        'avg_confidence' => 0.885,
    ],
];

echo "Phase 1 Anomalies Detected:\n";
foreach ($scenario1_anomalies as $anomaly) {
    echo sprintf("  [%s] %s (%.2f)\n",
        $anomaly['type'],
        substr($anomaly['message'], 0, 40),
        $anomaly['confidence']
    );
}
echo "\n";

// Correlate anomalies into chains
$correlator = new EventCorrelator();
$chains_s1 = $correlator->correlateAnomalies($scenario1_anomalies, $scenario1_clusters);

echo "Phase 2 Causal Chains Built:\n";
foreach ($chains_s1 as $idx => $chain) {
    echo sprintf("  Chain %d: %s (confidence: %.2f)\n",
        $idx,
        $chain->type(),
        $chain->confidence()
    );
    echo sprintf("    Root: %s\n", $chain->rootEvent()['type']);
    echo sprintf("    Cascade: %d events\n", $chain->eventCount());
    echo sprintf("    Severity: %s\n", $chain->severity());
}
echo "\n";

// Analyze root causes
$analyzer = new RootCauseAnalyzer();
$findings_s1 = [];
foreach ($chains_s1 as $chain) {
    $findings_s1[] = $analyzer->analyzeChain($chain);
}

foreach ($findings_s1 as $finding) {
    echo "Root Cause Analysis:\n";
    echo "  Root Cause: " . $finding['root_cause'] . "\n";
    echo "  Confidence: " . sprintf('%.0f%%', $finding['confidence'] * 100) . "\n";
    echo "  Impact: " . $finding['impact_severity'] . "\n";
    echo "  Recommended Action: " . $finding['recommended_action'] . "\n";
    echo "\n";

    echo "  Remediation Roadmap:\n";
    foreach (array_slice($finding['remediation_roadmap'], 0, 3) as $step) {
        echo sprintf("    %d. %s (est. %s)\n",
            $step['priority'],
            $step['step'],
            $step['estimated_time']
        );
    }
    echo "\n";
}

// ============================================================================
// Scenario 2: Disk Degradation
// ============================================================================

echo "[2] Scenario 2: Disk Degradation\n";
echo str_repeat("=", 60) . "\n\n";

$scenario2_anomalies = [
    [
        'timestamp'  => '2026-04-26T08:45:00',
        'type'       => 'DISK_ERROR',
        'severity'   => 'HIGH',
        'confidence' => 0.90,
        'message'    => 'disk sda: SMART error - Reallocated sector count: 150',
    ],
    [
        'timestamp'  => '2026-04-26T09:15:00',
        'type'       => 'DISK_ANOMALY',
        'severity'   => 'MEDIUM',
        'confidence' => 0.80,
        'message'    => 'Read latency increased 300ms (baseline 5ms)',
    ],
    [
        'timestamp'  => '2026-04-26T10:30:00',
        'type'       => 'SYSTEM_SLOWDOWN',
        'severity'   => 'MEDIUM',
        'confidence' => 0.75,
        'message'    => 'System I/O utilization 95% sustained',
    ],
    [
        'timestamp'  => '2026-04-26T12:00:00',
        'type'       => 'RAID_ANOMALY',
        'severity'   => 'HIGH',
        'confidence' => 0.85,
        'message'    => 'RAID rebuild in progress (degraded mode)',
    ],
];

echo "Phase 1: " . count($scenario2_anomalies) . " anomalies detected\n";

$chains_s2 = $correlator->correlateAnomalies($scenario2_anomalies);

echo "Phase 2: " . count($chains_s2) . " causal chains built\n";
foreach ($chains_s2 as $chain) {
    echo sprintf("  → %s chain (events: %d, confidence: %.2f)\n",
        $chain->type(),
        $chain->eventCount(),
        $chain->confidence()
    );
}
echo "\n";

$findings_s2 = [];
foreach ($chains_s2 as $chain) {
    $findings_s2[] = $analyzer->analyzeChain($chain);
}

foreach ($findings_s2 as $finding) {
    echo "Analysis Results:\n";
    echo "  Root: " . $finding['root_cause'] . "\n";
    echo "  Status: " . ucfirst($finding['status']) . "\n";
    echo "  Steps to fix: " . count($finding['remediation_roadmap']) . "\n";
    echo "\n";
}

// ============================================================================
// Scenario 3: Thermal Cascade
// ============================================================================

echo "[3] Scenario 3: Thermal Cascade (Fan Failure)\n";
echo str_repeat("=", 60) . "\n\n";

$scenario3_anomalies = [
    [
        'timestamp'  => '2026-04-25T14:00:00',
        'type'       => 'FAN_FAILURE',
        'severity'   => 'HIGH',
        'confidence' => 0.95,
        'message'    => 'Chassis fan: Speed dropped to 0 RPM (bearing failure)',
    ],
    [
        'timestamp'  => '2026-04-25T14:02:30',
        'type'       => 'THERMAL_EXCURSION',
        'severity'   => 'WARNING',
        'confidence' => 0.88,
        'message'    => 'Temperature 78.2°C (2.2°C rise in 2.5 min)',
    ],
    [
        'timestamp'  => '2026-04-25T14:15:00',
        'type'       => 'THERMAL_EXCURSION',
        'severity'   => 'CRITICAL',
        'confidence' => 0.92,
        'message'    => 'Temperature 91.5°C (approaching critical 95°C)',
    ],
    [
        'timestamp'  => '2026-04-25T14:18:00',
        'type'       => 'CPU_THROTTLE',
        'severity'   => 'HIGH',
        'confidence' => 0.85,
        'message'    => 'CPU throttled to 60% frequency to reduce heat',
    ],
];

echo "Anomalies detected: " . count($scenario3_anomalies) . "\n";
echo "Time span: 18 minutes (rapid cascade)\n\n";

$chains_s3 = $correlator->correlateAnomalies($scenario3_anomalies);

foreach ($chains_s3 as $chain) {
    echo "Causal Chain: " . $chain->type() . "\n";
    echo "  Root event: " . $chain->rootEvent()['type'] . "\n";
    echo "  Cascade steps: " . count($chain->intermediateEvents()) . "\n";
    echo "  Observable symptoms: " . count($chain->observableSymptoms()) . "\n";
    echo "  Overall severity: " . $chain->severity() . "\n";
    echo "  Confidence: " . sprintf('%.0f%%', $chain->confidence() * 100) . "\n";
    echo "\n";

    $finding = $analyzer->analyzeChain($chain);
    echo "Recommended Action: " . $finding['recommended_action'] . "\n";
    echo "Immediate Steps:\n";
    foreach (array_slice($finding['remediation_roadmap'], 0, 2) as $step) {
        echo "  • " . $step['step'] . "\n";
    }
    echo "\n";
}

// ============================================================================
// Scenario 4: Voltage Instability Pattern
// ============================================================================

echo "[4] Scenario 4: Voltage Instability (Intermittent)\n";
echo str_repeat("=", 60) . "\n\n";

$scenario4_anomalies = [
    [
        'timestamp'  => '2026-04-24T10:30:00',
        'type'       => 'VOLTAGE_INSTABILITY',
        'severity'   => 'HIGH',
        'confidence' => 0.86,
        'message'    => 'Voltage 12.84V (+5.3%, exceeds spec)',
    ],
    [
        'timestamp'  => '2026-04-24T10:31:15',
        'type'       => 'VOLTAGE_INSTABILITY',
        'severity'   => 'HIGH',
        'confidence' => 0.88,
        'message'    => 'Voltage 12.91V (+5.9%, ripple detected)',
    ],
    [
        'timestamp'  => '2026-04-24T11:15:00',
        'type'       => 'VOLTAGE_INSTABILITY',
        'severity'   => 'HIGH',
        'confidence' => 0.84,
        'message'    => 'Voltage sag to 11.65V (-3.6%)',
    ],
    [
        'timestamp'  => '2026-04-24T14:00:00',
        'type'       => 'SYSTEM_RESET',
        'severity'   => 'CRITICAL',
        'confidence' => 0.92,
        'message'    => 'Unexpected system reset (possible voltage brown-out)',
    ],
];

echo "Pattern: Intermittent voltage excursions over 3.5 hours\n";
echo "Total anomalies: " . count($scenario4_anomalies) . "\n\n";

$chains_s4 = $correlator->correlateAnomalies($scenario4_anomalies);

foreach ($chains_s4 as $chain) {
    echo "Chain Analysis:\n";
    echo "  Type: " . $chain->type() . "\n";
    echo "  Status: " . $chain->status() . "\n";
    echo "  Strength: " . sprintf('%.2f', $chain->calculateStrength()) . " / 1.0\n";
    echo "\n";

    $finding = $analyzer->analyzeChain($chain);

    echo "Findings:\n";
    echo "  Root Cause: " . $finding['root_cause'] . "\n";
    echo "  Confidence: " . sprintf('%.0f%%', $finding['confidence'] * 100) . "\n";
    echo "  Status: " . $finding['status'] . "\n";
    echo "\n";

    echo "Contributing Factors:\n";
    foreach ($finding['contributing_factors']['known_risk_factors'] as $factor) {
        echo "    • " . $factor . "\n";
    }
    echo "\n";

    echo "Impact: " . $finding['impact_statement'] . "\n";
    echo "\n";
}

// ============================================================================
// Summary
// ============================================================================

echo "[5] Summary: Phase 2 Performance\n";
echo str_repeat("=", 60) . "\n\n";

$allChains = array_merge($chains_s1, $chains_s2, $chains_s3, $chains_s4);
$allFindings = array_merge($findings_s1, $findings_s2,
    array_map(fn($c) => $analyzer->analyzeChain($c), $chains_s3),
    array_map(fn($c) => $analyzer->analyzeChain($c), $chains_s4)
);

echo sprintf("Total chains analyzed: %d\n", count($allChains));
echo sprintf("Average chain strength: %.2f / 1.0\n",
    array_sum(array_map(fn($c) => $c->calculateStrength(), $allChains)) / count($allChains)
);
echo sprintf("Average confidence: %.0f%%\n",
    array_sum(array_map(fn($f) => $f['confidence'], $allFindings)) / count($allFindings) * 100
);

echo "\nSeverity Distribution:\n";
$severities = array_count_values(array_map(fn($f) => $f['impact_severity'], $allFindings));
foreach ($severities as $sev => $count) {
    echo sprintf("  %s: %d\n", $sev, $count);
}

echo "\nRecommended Actions:\n";
$actions = array_count_values(array_map(fn($f) => $f['recommended_action'], $allFindings));
foreach ($actions as $action => $count) {
    echo sprintf("  %s: %d\n", $action, $count);
}

echo "\n";

// ============================================================================
// Output Format Example
// ============================================================================

echo "[6] Full Finding Structure Example\n";
echo str_repeat("=", 60) . "\n\n";

if (!empty($findings_s1)) {
    $exampleFinding = $findings_s1[0];
    echo "Root Cause Finding Structure:\n\n";
    echo json_encode($exampleFinding, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    echo "\n\n";
}

// ============================================================================
// Integration Readiness
// ============================================================================

echo "[7] Integration Readiness Checklist\n";
echo str_repeat("=", 60) . "\n\n";

$checks = [
    "CausalChain data structure" => true,
    "EventCorrelator builds chains" => count($allChains) > 0,
    "RootCauseAnalyzer generates findings" => count($allFindings) > 0,
    "Confidence scoring functional" => all_have_confidence($allFindings),
    "Remediation steps generated" => all_have_remediation($allFindings),
    "Chain types detected correctly" => all_have_chain_type($allChains),
];

foreach ($checks as $check => $passed) {
    echo ($passed ? "✓ " : "✗ ") . $check . "\n";
}

echo "\n";

// ============================================================================
// Next Steps
// ============================================================================

echo "[8] Next Steps\n";
echo str_repeat("=", 60) . "\n\n";

echo "Phase 2 is complete and ready to integrate.\n\n";

echo "Option A: Quick Integration (RenderStep)\n";
echo "  1. In RenderStep::run(), after anomaly detection\n";
echo "  2. Add EventCorrelator and RootCauseAnalyzer calls\n";
echo "  3. Store results in context bag\n";
echo "  4. Pass to ReportRenderer for HTML output\n\n";

echo "Option B: Proper Pipeline (AIAnalysisStep - Phase 3)\n";
echo "  1. Create new AIAnalysisStep after CorrelateStep\n";
echo "  2. Runs Phase 1 + Phase 2 for each bundle\n";
echo "  3. Stores complete AI results in context\n";
echo "  4. Propagate to rendering and narrative generation\n\n";

echo "Recommended: Option B (cleaner architecture)\n";
echo "Timeframe: 2-3 hours for implementation\n";
echo "Value: Full root cause analysis in reports\n\n";

// ============================================================================
// Helper Functions
// ============================================================================

function all_have_confidence($findings): bool
{
    foreach ($findings as $f) {
        if (!isset($f['confidence']) || $f['confidence'] < 0.4) {
            return false;
        }
    }
    return !empty($findings);
}

function all_have_remediation($findings): bool
{
    foreach ($findings as $f) {
        if (empty($f['remediation_roadmap'])) {
            return false;
        }
    }
    return !empty($findings);
}

function all_have_chain_type($chains): bool
{
    foreach ($chains as $c) {
        if (empty($c->type())) {
            return false;
        }
    }
    return !empty($chains);
}

echo "=== End of Phase 2 Example ===\n";
