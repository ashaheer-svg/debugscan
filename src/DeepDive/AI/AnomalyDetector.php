<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

use PDO;
use Psr\Log\LoggerInterface;

/**
 * Anomaly Detector: Orchestrator for Phase 1 AI analysis
 *
 * PURPOSE:
 * Main entry point for anomaly detection across power supply,
 * thermal, and hardware subsystems. Coordinates:
 * - Log preprocessing (selective log collection)
 * - Time series analysis (Z-score detection)
 * - Event-based anomaly detection
 * - Anomaly clustering and severity assessment
 *
 * INTEGRATION:
 * Called by AIAnalysisStep or directly by RenderStep
 * Accepts bundle metadata and hardware specs
 * Returns anomaly findings suitable for report rendering
 *
 * ARCHITECTURE:
 * AnomalyDetector → LogPreprocessor (smart log selection)
 *                ↓
 *                TimeSeriesAnalyzer (Z-score, statistics)
 *                ↓
 *                Output: anomalies with confidence scores
 *
 * @package App\DeepDive\AI
 */
final class AnomalyDetector
{
    private LogPreprocessor $logPreprocessor;
    private TimeSeriesAnalyzer $timeSeriesAnalyzer;
    private PDO $pdo;
    private ?LoggerInterface $logger;
    private int $tokenBudget;

    /**
     * Initialize anomaly detector
     *
     * @param PDO $pdo Database connection
     * @param ?LoggerInterface $logger Optional PSR-3 logger
     * @param int $tokenBudget Token budget per analysis (default 10000)
     */
    public function __construct(PDO $pdo, ?LoggerInterface $logger = null, int $tokenBudget = 10000)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
        $this->tokenBudget = $tokenBudget;
        $this->logPreprocessor = new LogPreprocessor($pdo, $tokenBudget);
        $this->timeSeriesAnalyzer = new TimeSeriesAnalyzer();
    }

    /**
     * Analyze a bundle for anomalies
     *
     * FLOW:
     * 1. Select and preprocess logs (respecting token budget)
     * 2. Extract power supply anomalies
     * 3. Extract thermal anomalies
     * 4. Extract hardware anomalies
     * 5. Cluster anomalies (find related events)
     * 6. Calculate composite severity scores
     * 7. Return findings with confidence levels
     *
     * @param string $bundlePath Extracted bundle directory
     * @param array $metadata Bundle metadata {psu_model, hardware_spec, etc}
     *
     * @return array{
     *     bundle_path: string,
     *     token_usage: int,
     *     findings: array{
     *         power_supply: array,
     *         thermal: array,
     *         hardware: array
     *     },
     *     clusters: array,
     *     overall_risk: 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL',
     *     summary: string
     * }
     */
    public function analyzeBundleAnomalies(string $bundlePath, array $metadata = []): array
    {
        $this->log('info', "Starting anomaly detection for bundle: {$bundlePath}");

        // === Step 1: Select and preprocess logs ===
        $logData = $this->logPreprocessor->selectLogs($bundlePath, $metadata);
        $this->log('info', "Log selection complete. Token usage: {$logData['estimated_tokens']}");

        // === Step 2: Analyze power supply events ===
        $psuFindings = $this->analyzePowerSupply($logData);

        // === Step 3: Analyze thermal events ===
        $thermalFindings = $this->analyzeThermal($logData);

        // === Step 4: Analyze hardware events ===
        $hwFindings = $this->analyzeHardware($logData);

        // === Step 5: Cluster all anomalies ===
        $allAnomalies = array_merge(
            $psuFindings['anomalies'] ?? [],
            $thermalFindings['anomalies'] ?? [],
            $hwFindings['anomalies'] ?? []
        );
        $clusters = $this->timeSeriesAnalyzer->clusterAnomalies($allAnomalies);

        // === Step 6: Calculate overall risk ===
        $overallRisk = $this->calculateOverallRisk($psuFindings, $thermalFindings, $hwFindings, $clusters);

        // === Step 7: Generate summary ===
        $summary = $this->generateSummary($psuFindings, $thermalFindings, $hwFindings, $overallRisk);

        $this->log('info', "Anomaly detection complete. Risk level: {$overallRisk}");

        return [
            'bundle_path'   => $bundlePath,
            'token_usage'   => $logData['estimated_tokens'],
            'findings'      => [
                'power_supply' => $psuFindings,
                'thermal'      => $thermalFindings,
                'hardware'     => $hwFindings,
            ],
            'clusters'      => $clusters,
            'overall_risk'  => $overallRisk,
            'summary'       => $summary,
        ];
    }

    /**
     * Analyze power supply subsystem for anomalies
     *
     * Looks for:
     * - PSU not detected / not present
     * - Voltage instability (out of spec)
     * - Redundant PSU failures in dual-PSU systems
     * - Voltage spike/sag patterns
     *
     * @param array $logData Log data from LogPreprocessor
     *
     * @return array{anomalies: array, summary: string, risk_level: string}
     */
    private function analyzePowerSupply(array $logData): array
    {
        $anomalies = [];
        $issues = [];

        // === PRIMARY: Check structured power_data (from PowerSupplyParser) ===
        // This is the most reliable source since data was already parsed and validated
        if (!empty($logData['power_data'] ?? null)) {
            $powerData = $logData['power_data'];
            $this->log('debug', 'Analyzing structured power_data from PowerSupplyParser');

            // Check health assessment first (highest priority)
            if (isset($powerData['health_assessment'])) {
                $health = $powerData['health_assessment'];

                if ($health['overall_status'] === 'critical') {
                    $anomalies[] = [
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'type'         => 'PSU_HEALTH_CRITICAL',
                        'severity'     => 'CRITICAL',
                        'confidence'   => 0.95,
                        'message'      => 'Power supply health assessment: CRITICAL - ' . implode(', ', $health['risk_factors'] ?? []),
                    ];
                    $issues[] = 'Critical power supply health issue';
                } elseif ($health['overall_status'] === 'warning') {
                    $anomalies[] = [
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'type'         => 'PSU_HEALTH_WARNING',
                        'severity'     => 'HIGH',
                        'confidence'   => 0.85,
                        'message'      => 'Power supply health assessment: WARNING - ' . implode(', ', $health['risk_factors'] ?? []),
                    ];
                    $issues[] = 'Power supply health warning';
                } elseif ($health['overall_status'] === 'caution') {
                    $anomalies[] = [
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'type'         => 'PSU_HEALTH_CAUTION',
                        'severity'     => 'MEDIUM',
                        'confidence'   => 0.75,
                        'message'      => 'Power supply health assessment: CAUTION - ' . implode(', ', $health['risk_factors'] ?? []),
                    ];
                    $issues[] = 'Power supply caution';
                }

                // Check redundancy status
                $redundancy = $health['redundancy_status'] ?? 'none';
                if ($redundancy === 'degraded') {
                    $anomalies[] = [
                        'timestamp'    => date('Y-m-d H:i:s'),
                        'type'         => 'PSU_REDUNDANCY_DEGRADED',
                        'severity'     => 'HIGH',
                        'confidence'   => 0.90,
                        'message'      => 'Redundant PSU configuration is degraded - one PSU may have failed',
                    ];
                    $issues[] = 'Redundant PSU degraded';
                }
            }

            // Check individual PSU status
            if (!empty($powerData['power_supplies'])) {
                foreach ($powerData['power_supplies'] as $psu) {
                    $status = $psu['status'] ?? 'unknown';
                    $detection = $psu['detection_status'] ?? 'unknown';
                    $plugged = $psu['plugged'] ?? null;

                    if ($status === 'failed' || $detection === 'not_present' || $plugged === false) {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'PSU_FAILED',
                            'severity'     => 'CRITICAL',
                            'confidence'   => 0.99,
                            'message'      => sprintf(
                                'PSU #%d: status=%s, detection=%s, plugged=%s',
                                $psu['index'] ?? 0,
                                $status,
                                $detection,
                                $plugged === null ? 'unknown' : ($plugged ? 'yes' : 'no')
                            ),
                        ];
                        $issues[] = 'PSU failure or not detected';
                    } elseif ($status === 'degraded') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'PSU_DEGRADED',
                            'severity'     => 'HIGH',
                            'confidence'   => 0.85,
                            'message'      => sprintf('PSU #%d: degraded status detected', $psu['index'] ?? 0),
                        ];
                        $issues[] = 'PSU degradation';
                    }
                }
            }

            // Check voltage readings for out-of-spec conditions
            if (!empty($powerData['voltage_readings'])) {
                foreach ($powerData['voltage_readings'] as $voltage) {
                    $status = $voltage['status'] ?? 'ok';

                    if ($status === 'critical') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'VOLTAGE_CRITICAL',
                            'severity'     => 'CRITICAL',
                            'confidence'   => 0.92,
                            'message'      => sprintf(
                                '%s: %.2fV (out of spec, expected %.2f-%.2fV)',
                                $voltage['rail_name'],
                                $voltage['voltage_volts'],
                                $voltage['min_volts'] ?? 0,
                                $voltage['max_volts'] ?? 0
                            ),
                        ];
                        $issues[] = 'Critical voltage out of spec';
                    } elseif ($status === 'warning') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'VOLTAGE_WARNING',
                            'severity'     => 'HIGH',
                            'confidence'   => 0.80,
                            'message'      => sprintf(
                                '%s: %.2fV (approaching limit)',
                                $voltage['rail_name'],
                                $voltage['voltage_volts']
                            ),
                        ];
                        $issues[] = 'Voltage approaching limit';
                    }
                }
            }

            // Check current readings for over-current
            if (!empty($powerData['current_readings'])) {
                foreach ($powerData['current_readings'] as $current) {
                    $status = $current['status'] ?? 'ok';

                    if ($status === 'critical') {
                        $anomalies[] = [
                            'timestamp'    => date('Y-m-d H:i:s'),
                            'type'         => 'CURRENT_CRITICAL',
                            'severity'     => 'HIGH',
                            'confidence'   => 0.85,
                            'message'      => sprintf('%s: %.2fA (critical)', $current['rail_name'], $current['current_amps']),
                        ];
                        $issues[] = 'Over-current condition';
                    }
                }
            }

            // Log what was found
            if (!empty($anomalies)) {
                $this->log('info', 'Found ' . count($anomalies) . ' power anomalies from health assessment');
            }
        }

        // === FALLBACK: Check IPMI PSU events (if no power_data available) ===
        // This is secondary analysis for systems where PowerSupplyParser data isn't available
        $psuEvents = array_filter(
            $logData['ipmi_events'] ?? [],
            fn($e) => ($e['type'] ?? '') === 'IPMI_PSU'
        );

        foreach ($psuEvents as $event) {
            $severity = $event['severity'] ?? 'INFO';

            // Check for "Not Present" or "Not Plugged"
            if (preg_match('/not\s+(present|plugged)/i', $event['message'] ?? '')) {
                $anomalies[] = [
                    'timestamp'    => $event['timestamp'],
                    'type'         => 'PSU_NOT_DETECTED',
                    'severity'     => 'CRITICAL',
                    'confidence'   => 0.99,
                    'message'      => $event['message'],
                ];
                $issues[] = 'PSU not detected or not plugged in';
            } elseif (preg_match('/fail/i', $event['message'] ?? '')) {
                $anomalies[] = [
                    'timestamp'    => $event['timestamp'],
                    'type'         => 'PSU_FAILURE',
                    'severity'     => 'CRITICAL',
                    'confidence'   => 0.95,
                    'message'      => $event['message'],
                ];
                $issues[] = 'PSU failure detected';
            } elseif ($severity === 'CRITICAL') {
                $anomalies[] = [
                    'timestamp'    => $event['timestamp'],
                    'type'         => 'PSU_ANOMALY',
                    'severity'     => 'HIGH',
                    'confidence'   => 0.85,
                    'message'      => $event['message'],
                ];
                $issues[] = 'PSU anomaly: ' . substr($event['message'], 0, 50);
            }
        }

        // === Check voltage stability ===
        $voltageEvents = array_filter(
            $logData['ipmi_events'] ?? [],
            fn($e) => ($e['type'] ?? '') === 'IPMI_VOLTAGE'
        );

        if (!empty($voltageEvents)) {
            $voltageAnomalies = $this->analyzeVoltageStability($voltageEvents);
            $anomalies = array_merge($anomalies, $voltageAnomalies);
            if (!empty($voltageAnomalies)) {
                $issues[] = 'Voltage instability detected';
            }
        }

        $riskLevel = empty($anomalies) ? 'LOW' : (
            count($anomalies) >= 3 ? 'CRITICAL' : (count($anomalies) >= 2 ? 'HIGH' : 'MEDIUM')
        );

        return [
            'anomalies'  => $anomalies,
            'summary'    => empty($issues) ? 'No power supply anomalies detected' : 'Issues: ' . implode('; ', $issues),
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Analyze thermal subsystem for anomalies
     *
     * Looks for:
     * - Temperature excursions (above thresholds)
     * - Thermal trends (rising temperatures)
     * - Anomalous cooling behavior
     *
     * @param array $logData Log data from LogPreprocessor
     *
     * @return array{anomalies: array, summary: string, risk_level: string}
     */
    private function analyzeThermal(array $logData): array
    {
        $anomalies = [];
        $issues = [];

        // === Check thermal events ===
        $thermalEvents = array_filter(
            $logData['ipmi_events'] ?? [],
            fn($e) => ($e['type'] ?? '') === 'IPMI_THERMAL'
        );

        // Convert to time series for analysis
        $thermalSeries = array_map(fn($e) => [
            'timestamp' => $e['timestamp'],
            'value'     => $this->extractTemperatureValue($e['message'] ?? ''),
        ], $thermalEvents);

        if (!empty($thermalSeries)) {
            $analysis = $this->timeSeriesAnalyzer->analyzeTimeSeries($thermalSeries, [
                'hard_max' => 95,  // Critical temp
                'hard_min' => 0,
                'expected_range' => ['min' => 30, 'max' => 80],
            ]);

            foreach ($analysis['anomalies'] ?? [] as $anomaly) {
                $anomalies[] = [
                    'timestamp'    => $anomaly['timestamp'],
                    'type'         => 'THERMAL_EXCURSION',
                    'severity'     => $anomaly['value'] > 95 ? 'CRITICAL' : 'WARNING',
                    'confidence'   => $anomaly['confidence'],
                    'message'      => sprintf('Temperature %.1f°C', $anomaly['value']),
                    'z_score'      => $anomaly['z_score'],
                ];
                $issues[] = sprintf('Thermal anomaly at %.1f°C', $anomaly['value']);
            }
        }

        $riskLevel = empty($anomalies) ? 'LOW' : (
            count($anomalies) >= 2 ? 'HIGH' : 'MEDIUM'
        );

        return [
            'anomalies'  => $anomalies,
            'summary'    => empty($issues) ? 'Thermal subsystem nominal' : 'Issues: ' . implode('; ', $issues),
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Analyze hardware subsystem for anomalies
     *
     * Looks for:
     * - Disk errors or degradation
     * - Memory errors
     * - RAID issues
     * - Fan failures
     *
     * @param array $logData Log data from LogPreprocessor
     *
     * @return array{anomalies: array, summary: string, risk_level: string}
     */
    private function analyzeHardware(array $logData): array
    {
        $anomalies = [];
        $issues = [];

        // === Check kernel logs for hardware errors ===
        $kernelLogs = $logData['kernel_logs'] ?? [];
        foreach ($kernelLogs as $log) {
            $msg = $log['message'] ?? '';

            if (stripos($msg, 'disk') !== false || stripos($msg, 'sda') !== false) {
                $anomalies[] = [
                    'timestamp'    => $log['timestamp'],
                    'type'         => 'DISK_ERROR',
                    'severity'     => 'HIGH',
                    'confidence'   => 0.9,
                    'message'      => substr($msg, 0, 100),
                ];
                $issues[] = 'Disk error detected';
            } elseif (stripos($msg, 'fan') !== false) {
                $anomalies[] = [
                    'timestamp'    => $log['timestamp'],
                    'type'         => 'FAN_FAILURE',
                    'severity'     => 'HIGH',
                    'confidence'   => 0.9,
                    'message'      => substr($msg, 0, 100),
                ];
                $issues[] = 'Fan failure detected';
            } elseif (stripos($msg, 'raid') !== false || stripos($msg, 'md') !== false) {
                $anomalies[] = [
                    'timestamp'    => $log['timestamp'],
                    'type'         => 'RAID_ANOMALY',
                    'severity'     => 'HIGH',
                    'confidence'   => 0.85,
                    'message'      => substr($msg, 0, 100),
                ];
                $issues[] = 'RAID issue detected';
            }
        }

        // === Check Synology events ===
        $synoEvents = $logData['syno_events'] ?? [];
        foreach ($synoEvents as $event) {
            $msg = $event['message'] ?? '';
            if (stripos($msg, 'disk') !== false) {
                $anomalies[] = [
                    'timestamp'    => $event['timestamp'],
                    'type'         => 'DISK_ANOMALY',
                    'severity'     => 'MEDIUM',
                    'confidence'   => 0.8,
                    'message'      => substr($msg, 0, 100),
                ];
                if (!in_array('Disk anomaly', $issues)) {
                    $issues[] = 'Disk anomaly';
                }
            }
        }

        $riskLevel = empty($anomalies) ? 'LOW' : (
            count($anomalies) >= 3 ? 'HIGH' : 'MEDIUM'
        );

        return [
            'anomalies'  => $anomalies,
            'summary'    => empty($issues) ? 'Hardware subsystem nominal' : 'Issues: ' . implode('; ', $issues),
            'risk_level' => $riskLevel,
        ];
    }

    /**
     * Analyze voltage stability from IPMI events
     *
     * @param array $voltageEvents Voltage events
     *
     * @return array Anomalies
     */
    private function analyzeVoltageStability(array $voltageEvents): array
    {
        $anomalies = [];
        $voltages = array_map(fn($e) => [
            'timestamp' => $e['timestamp'],
            'value'     => $this->extractVoltageValue($e['message'] ?? ''),
        ], $voltageEvents);

        // Filter out zero values
        $voltages = array_filter($voltages, fn($v) => $v['value'] > 0);

        if (count($voltages) >= 5) {
            $analysis = $this->timeSeriesAnalyzer->analyzeTimeSeries($voltages, [
                'hard_max' => 12.6,  // +5% for 12V rail
                'hard_min' => 11.4,  // -5%
            ]);

            foreach ($analysis['anomalies'] ?? [] as $anomaly) {
                $anomalies[] = [
                    'timestamp'    => $anomaly['timestamp'],
                    'type'         => 'VOLTAGE_INSTABILITY',
                    'severity'     => 'HIGH',
                    'confidence'   => $anomaly['confidence'],
                    'message'      => sprintf('Voltage out of spec: %.2fV', $anomaly['value']),
                ];
            }
        }

        return $anomalies;
    }

    /**
     * Calculate overall risk level
     *
     * Combines findings from all subsystems
     *
     * @param array $psuFindings Power supply findings
     * @param array $thermalFindings Thermal findings
     * @param array $hwFindings Hardware findings
     * @param array $clusters Anomaly clusters
     *
     * @return string 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL'
     */
    private function calculateOverallRisk(array $psuFindings, array $thermalFindings, array $hwFindings, array $clusters): string
    {
        // Check for CRITICAL anomalies
        $critical = 0;
        $high = 0;

        foreach ([$psuFindings, $thermalFindings, $hwFindings] as $findings) {
            foreach ($findings['anomalies'] ?? [] as $anomaly) {
                if ($anomaly['severity'] === 'CRITICAL') {
                    $critical++;
                } elseif ($anomaly['severity'] === 'HIGH') {
                    $high++;
                }
            }
        }

        // Check clusters
        foreach ($clusters as $cluster) {
            if ($cluster['severity'] === 'CRITICAL') {
                $critical += 2;
            } elseif ($cluster['severity'] === 'HIGH') {
                $high += 1;
            }
        }

        if ($critical >= 2) return 'CRITICAL';
        if ($critical >= 1 || $high >= 3) return 'HIGH';
        if ($high >= 1) return 'MEDIUM';

        return 'LOW';
    }

    /**
     * Generate summary of findings
     *
     * @param array $psuFindings Power supply findings
     * @param array $thermalFindings Thermal findings
     * @param array $hwFindings Hardware findings
     * @param string $overallRisk Overall risk level
     *
     * @return string Human-readable summary
     */
    private function generateSummary(array $psuFindings, array $thermalFindings, array $hwFindings, string $overallRisk): string
    {
        $parts = [];

        if (($psuFindings['risk_level'] ?? 'LOW') !== 'LOW') {
            $parts[] = "Power supply: " . ($psuFindings['summary'] ?? 'OK');
        }

        if (($thermalFindings['risk_level'] ?? 'LOW') !== 'LOW') {
            $parts[] = "Thermal: " . ($thermalFindings['summary'] ?? 'OK');
        }

        if (($hwFindings['risk_level'] ?? 'LOW') !== 'LOW') {
            $parts[] = "Hardware: " . ($hwFindings['summary'] ?? 'OK');
        }

        if (empty($parts)) {
            return "No significant anomalies detected. System nominal.";
        }

        return "Risk: {$overallRisk}. " . implode(". ", $parts);
    }

    /**
     * Extract temperature value from message string
     *
     * @param string $message Message containing temperature
     *
     * @return float Temperature in Celsius, or 0 if not found
     */
    private function extractTemperatureValue(string $message): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:°C|C)/i', $message, $m)) {
            return floatval($m[1]);
        }
        return 0;
    }

    /**
     * Extract voltage value from message string
     *
     * @param string $message Message containing voltage
     *
     * @return float Voltage in volts, or 0 if not found
     */
    private function extractVoltageValue(string $message): float
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*V/i', $message, $m)) {
            return floatval($m[1]);
        }
        return 0;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[anomaly-detector] ' . $message);
        }
    }
}
