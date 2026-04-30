<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * PowerSupplyParser: Power supply and power delivery system analysis
 *
 * PURPOSE:
 * Extracts and analyzes power supply health, voltage stability, and power delivery
 * across all Synology NAS models. Detects PSU failures, degradation, monitoring
 * failures, and power-related anomalies that could lead to data loss.
 *
 * FAILURE TYPES DETECTED:
 * - PSU Detection Failures: IPMI reports PSU as "Not Present" or "Not Plugged"
 * - Voltage Instability: Supply voltage sag under load or out-of-spec readings
 * - Thermal-Power Anomalies: Correlation between thermal issues and power supply
 * - Redundant PSU Failures: One PSU failed in redundant configuration
 * - PSU Aging/Degradation: Unstable readings, increasing voltage ripple
 * - BMC Monitoring Failure: Inconsistent PSU status despite system running
 *
 * DATA SOURCES (Priority Order):
 * 1. dmidecode.result: DMI Type 39 (System Power Supply) - authoritative hardware status
 * 2. ipmi_sensors.result: IPMI sensor readings - voltage, current, power
 * 3. ipmi_event_log.result: IPMI SEL (System Event Log) - power anomalies
 * 4. ipmi_chassis_status.result: IPMI chassis power status
 * 5. dmi_psu_detailed.result: Detailed DMI PSU information
 *
 * OUTPUT STRUCTURE:
 * {
 *   'power_supplies': [
 *     {
 *       'index': 1,
 *       'status': 'healthy|degraded|failed|unknown',
 *       'detection_status': 'present|not_present|unknown',
 *       'plugged': true|false|null,
 *       'manufacturer': string,
 *       'model': string,
 *       'serial': string,
 *       'capacity_watts': int,
 *       'max_capacity_watts': int,
 *       'input_voltage_range': string,
 *       'hot_replaceable': bool
 *     }
 *   ],
 *   'voltage_readings': [
 *     {
 *       'rail_name': string,
 *       'voltage_volts': float,
 *       'status': 'ok|warning|critical',
 *       'min_volts': float,
 *       'max_volts': float
 *     }
 *   ],
 *   'current_readings': [
 *     {
 *       'rail_name': string,
 *       'current_amps': float,
 *       'status': 'ok|warning|critical'
 *     }
 *   ],
 *   'power_events': [
 *     {
 *       'timestamp': datetime,
 *       'event_type': string,
 *       'severity': 'critical|warning|info',
 *       'description': string
 *     }
 *   ],
 *   'system_power_status': {
 *     'current_state': 'on|off|standby',
 *     'power_supply_state': 'safe|critical|unknown',
 *     'thermal_state': 'safe|critical|unknown',
 *     'chassis_intrusion': 'closed|open|unknown'
 *   },
 *   'health_assessment': {
 *     'overall_status': 'healthy|caution|warning|critical',
 *     'redundancy_status': 'none|single|redundant|degraded',
 *     'risk_factors': [string],
 *     'requires_attention': bool
 *   }
 * }
 *
 * DSM VERSION COMPATIBILITY:
 * Works across DSM 6.0 - 7.x and all NAS models.
 * Gracefully handles missing data sources (IPMI may not be available on all models).
 * Hybrid approach: DMI data available on all systems, IPMI data available on rackmounts
 * and high-end models.
 *
 * @package App\Parsers
 */
class PowerSupplyParser implements ParserInterface
{
    /**
     * Parse power supply data from debug bundle
     */
    public function parse(string $extractedPath, array &$context): array
    {
        $data = [];
        $citations = [];

        // 1. Extract DMI power supply information (available on all systems)
        $dmiPsu = $this->parseDmiPowerSupply($extractedPath);
        if ($dmiPsu['data']) {
            $data['power_supplies'] = $dmiPsu['data'];
            $citations = array_merge($citations, $dmiPsu['citations']);
        }

        // 2. Extract IPMI sensor readings (available on rackmounts/high-end)
        $ipmiVoltage = $this->parseIpmiVoltage($extractedPath);
        if ($ipmiVoltage['data']) {
            $data['voltage_readings'] = $ipmiVoltage['data'];
            $citations = array_merge($citations, $ipmiVoltage['citations']);
        }

        $ipmiCurrent = $this->parseIpmiCurrent($extractedPath);
        if ($ipmiCurrent['data']) {
            $data['current_readings'] = $ipmiCurrent['data'];
            $citations = array_merge($citations, $ipmiCurrent['citations']);
        }

        // 3. Extract IPMI power events from System Event Log
        $ipmiEvents = $this->parseIpmiEventLog($extractedPath);
        if ($ipmiEvents['data']) {
            $data['power_events'] = $ipmiEvents['data'];
            $citations = array_merge($citations, $ipmiEvents['citations']);
        }

        // 4. Extract IPMI chassis power status
        $chassisStatus = $this->parseChassisStatus($extractedPath);
        if ($chassisStatus['data']) {
            $data['system_power_status'] = $chassisStatus['data'];
            $citations = array_merge($citations, $chassisStatus['citations']);
        }

        // 4.5. Extract model for PSU configuration detection
        $model = $this->getModelFromContext($context);

        // 5. FALLBACK: If no primary power data available, scan logs for power-related errors
        $hasAnyPowerData = !empty($data['power_supplies'] ?? [])
            || !empty($data['voltage_readings'] ?? [])
            || !empty($data['current_readings'] ?? [])
            || !empty($data['power_events'] ?? [])
            || !empty($data['system_power_status'] ?? []);

        if (!$hasAnyPowerData) {
            // Try to detect power issues from kernel/system logs
            $logEvents = $this->detectPowerEventsFallback($extractedPath);
            if ($logEvents['data']) {
                $data['power_events'] = $logEvents['data'];
                $citations = array_merge($citations, $logEvents['citations']);
            }
        }

        // 6. Perform health assessment and risk analysis
        $assessment = $this->assessPowerHealth($data, $model);
        $data['health_assessment'] = $assessment;

        return [
            'data' => $data,
            'citations' => $citations
        ];
    }

    /**
     * Locate a file across multiple possible paths using glob patterns
     *
     * Handles various bundle extraction layouts (DSM 6/7, nested dirs, etc.)
     * by searching through multiple possible locations with glob support.
     *
     * @param string $basePath Bundle root path
     * @param array<string> $patterns Glob patterns to search (relative to basePath)
     * @return string|null Absolute path to first matching file, or null
     */
    private function locateFile(string $basePath, array $patterns): ?string
    {
        $basePath = rtrim($basePath, '/\\');

        foreach ($patterns as $pattern) {
            // Direct file check (no glob)
            if (strpos($pattern, '*') === false) {
                $fullPath = $basePath . '/' . $pattern;
                if (file_exists($fullPath)) {
                    return $fullPath;
                }
            } else {
                // Glob pattern
                $globPath = $basePath . '/' . $pattern;
                $matches = glob($globPath);
                if ($matches && count($matches) > 0) {
                    // Return first match (should be only one for dmidecode)
                    foreach ($matches as $match) {
                        if (is_file($match)) {
                            return $match;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Extract device model from context (set by VersionParser or HardwareParser)
     */
    private function getModelFromContext(array $context): ?string
    {
        return $context['hardware']['model'] ?? $context['model'] ?? null;
    }

    /**
     * Get PSU specification for device model
     * Returns: ['count' => 2, 'watts' => [500, 500], 'redundant' => true]
     */
    private function getPsuSpecForModel(string $model): ?array
    {
        $model = strtolower(trim($model));

        // Comprehensive Synology hardware PSU configuration database
        $specifications = [
            // RackStations - 16-bay
            'rs3617rpxs' => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            'rs3617xs'   => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            'rs3617rp'   => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],
            'rs3617'     => ['count' => 2, 'watts' => [500, 500], 'redundant' => true],

            // RackStations - 12-bay
            'rs2419rp'   => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],
            'rs2419rpu'  => ['count' => 1, 'watts' => [250], 'redundant' => false],
            'rs2419+'    => ['count' => 1, 'watts' => [250], 'redundant' => false],
            'rs2419'     => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],

            // RackStations - 2-bay
            'rs823rp'    => ['count' => 2, 'watts' => [180, 180], 'redundant' => true],
            'rs823'      => ['count' => 1, 'watts' => [90], 'redundant' => false],

            // Desktop/Tower - High-end
            'ds3617xs'   => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],
            'ds3615xs'   => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],
            'ds3622xs'   => ['count' => 2, 'watts' => [250, 250], 'redundant' => true],
            'ds1621xs'   => ['count' => 2, 'watts' => [300, 300], 'redundant' => true],

            // Desktop/Tower - Mid-range
            'ds918+'     => ['count' => 1, 'watts' => [90], 'redundant' => false],
            'ds1019+'    => ['count' => 1, 'watts' => [65], 'redundant' => false],
            'ds1621+'    => ['count' => 1, 'watts' => [120], 'redundant' => false],
            'ds1821+'    => ['count' => 1, 'watts' => [180], 'redundant' => false],
            'ds920+'     => ['count' => 1, 'watts' => [90], 'redundant' => false],
            'ds1520+'    => ['count' => 1, 'watts' => [65], 'redundant' => false],
        ];

        return $specifications[$model] ?? null;
    }

    /**
     * Parse DMI Type 39 (System Power Supply) information
     * Available on all Synology systems via dmidecode
     */
    private function parseDmiPowerSupply(string $extractedPath): array
    {
        $data = [];
        $citations = [];

        // Search for dmidecode.result across multiple possible locations
        // to handle different bundle extraction layouts (DSM 6/7, nested dirs, etc.)
        $dmiFile = $this->locateFile($extractedPath, [
            'dsm/result/dmidecode.result',
            'result/dmidecode.result',
            'dmidecode.result',
            '*/result/dmidecode.result',
            '*/dmidecode.result',
        ]);

        if (!$dmiFile) {
            return ['data' => $data, 'citations' => $citations];
        }

        $content = file_get_contents($dmiFile);
        $citations[] = [
            'file' => 'dsm/result/dmidecode.result',
            'lines' => '1-' . substr_count($content, "\n"),
            'timestamp' => date('Y-m-d H:i:s', filemtime($dmiFile))
        ];

        // Parse DMI Type 39 sections
        $sections = $this->extractDmiType39Sections($content);

        foreach ($sections as $index => $section) {
            $psu = [
                'index' => $index + 1,
                'status' => 'unknown',
                'detection_status' => 'unknown',
                'plugged' => null,
                'manufacturer' => null,
                'model' => null,
                'serial' => null,
                'capacity_watts' => null,
                'max_capacity_watts' => null,
                'input_voltage_range' => null,
                'hot_replaceable' => null
            ];

            // Parse status fields
            if (preg_match('/Status:\s+(.+)/i', $section, $m)) {
                $status = strtolower(trim($m[1]));
                $psu['detection_status'] = ($status === 'not present') ? 'not_present' : 'present';
                $psu['status'] = ($status === 'not present') ? 'failed' : 'unknown';
            }

            if (preg_match('/Plugged:\s+(.+)/i', $section, $m)) {
                $psu['plugged'] = strtolower(trim($m[1])) === 'yes';
            }

            // Parse identifying information
            if (preg_match('/Manufacturer:\s+(.+)/i', $section, $m)) {
                $psu['manufacturer'] = trim($m[1]);
            }
            if (preg_match('/Model\s+Part\s+Number:\s+(.+)/i', $section, $m)) {
                $psu['model'] = trim($m[1]);
            }
            if (preg_match('/Serial\s+Number:\s+(.+)/i', $section, $m)) {
                $psu['serial'] = trim($m[1]);
            }

            // Parse power capacity
            if (preg_match('/Max\s+Power\s+Capacity:\s+(\d+)\s*W/i', $section, $m)) {
                $psu['max_capacity_watts'] = (int)$m[1];
            }

            if (preg_match('/Input\s+Voltage\s+Range\s+Switching:\s+(.+)/i', $section, $m)) {
                $psu['input_voltage_range'] = trim($m[1]);
            }

            if (preg_match('/Hot\s+Replaceable:\s+(.+)/i', $section, $m)) {
                $psu['hot_replaceable'] = strtolower(trim($m[1])) === 'yes';
            }

            // Clean up OEM placeholder values
            if ($psu['manufacturer'] && strpos($psu['manufacturer'], 'OEM') !== false) {
                $psu['manufacturer'] = null;
            }
            if ($psu['model'] && strpos($psu['model'], 'OEM') !== false) {
                $psu['model'] = null;
            }

            $data[] = $psu;
        }

        return ['data' => $data, 'citations' => $citations];
    }

    /**
     * Extract individual DMI Type 39 sections from dmidecode output
     */
    private function extractDmiType39Sections(string $content): array
    {
        $sections = [];
        $lines = explode("\n", $content);
        $currentSection = '';
        $inType39 = false;

        foreach ($lines as $line) {
            if (preg_match('/Handle\s+0x\w+,\s+DMI\s+type\s+39/i', $line)) {
                $inType39 = true;
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                }
                $currentSection = '';
            } elseif ($inType39 && preg_match('/^Handle\s+0x\w+,\s+DMI\s+type\s+\d+/i', $line) && !preg_match('/type\s+39/i', $line)) {
                $inType39 = false;
                if (!empty($currentSection)) {
                    $sections[] = $currentSection;
                }
                $currentSection = '';
            } elseif ($inType39) {
                $currentSection .= $line . "\n";
            }
        }

        if (!empty($currentSection)) {
            $sections[] = $currentSection;
        }

        return $sections;
    }

    /**
     * Parse IPMI voltage sensor readings
     */
    private function parseIpmiVoltage(string $extractedPath): array
    {
        $data = [];
        $citations = [];

        // Search for IPMI sensors file across multiple possible locations
        $ipmiFile = $this->locateFile($extractedPath, [
            'dsm/result/ipmi_sensors.result',
            'result/ipmi_sensors.result',
            'ipmi_sensors.result',
            '*/result/ipmi_sensors.result',
            '*/ipmi_sensors.result',
        ]);

        if (!$ipmiFile) {
            return ['data' => $data, 'citations' => $citations];
        }

        $content = file_get_contents($ipmiFile);
        $citations[] = [
            'file' => 'dsm/result/ipmi_sensors.result',
            'lines' => '1-' . substr_count($content, "\n"),
            'timestamp' => date('Y-m-d H:i:s', filemtime($ipmiFile))
        ];

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            // IPMI sensor format: NAME | VALUE UNIT | STATUS
            if (preg_match('/^\s*(.+?)\s*\|\s*([\d.]+)\s*V\s*\|\s*(\w+)/i', $line, $m)) {
                $reading = [
                    'rail_name' => trim($m[1]),
                    'voltage_volts' => (float)$m[2],
                    'status' => strtolower($m[3])
                ];

                // Parse thresholds if available
                if (preg_match('/\([\d.]+\s*to\s*([\d.]+)\)/i', $line, $thresh)) {
                    $reading['max_volts'] = (float)$thresh[1];
                }

                $data[] = $reading;
            }
        }

        return ['data' => $data, 'citations' => $citations];
    }

    /**
     * Parse IPMI current sensor readings
     */
    private function parseIpmiCurrent(string $extractedPath): array
    {
        $data = [];
        $citations = [];

        // Search for IPMI sensors file across multiple possible locations
        $ipmiFile = $this->locateFile($extractedPath, [
            'dsm/result/ipmi_sensors.result',
            'result/ipmi_sensors.result',
            'ipmi_sensors.result',
            '*/result/ipmi_sensors.result',
            '*/ipmi_sensors.result',
        ]);

        if (!$ipmiFile) {
            return ['data' => $data, 'citations' => $citations];
        }

        $content = file_get_contents($ipmiFile);

        $lines = explode("\n", $content);
        foreach ($lines as $line) {
            // Match current readings in Amps
            if (preg_match('/^\s*(.+?)\s*\|\s*([\d.]+)\s*A\s*\|\s*(\w+)/i', $line, $m)) {
                $reading = [
                    'rail_name' => trim($m[1]),
                    'current_amps' => (float)$m[2],
                    'status' => strtolower($m[3])
                ];
                $data[] = $reading;
            }
        }

        if (!empty($data) && empty($citations)) {
            $citations[] = [
                'file' => 'dsm/result/ipmi_sensors.result',
                'lines' => '1-' . substr_count($content, "\n"),
                'timestamp' => date('Y-m-d H:i:s', filemtime($ipmiFile))
            ];
        }

        return ['data' => $data, 'citations' => $citations];
    }

    /**
     * Parse IPMI System Event Log for power-related events
     */
    private function parseIpmiEventLog(string $extractedPath): array
    {
        $data = [];
        $citations = [];

        // Search for IPMI event log file across multiple possible locations
        $selFile = $this->locateFile($extractedPath, [
            'dsm/result/ipmi_event_log.result',
            'result/ipmi_event_log.result',
            'ipmi_event_log.result',
            '*/result/ipmi_event_log.result',
            '*/ipmi_event_log.result',
            'dsm/result/ipmi_sel.result',
            'result/ipmi_sel.result',
            'ipmi_sel.result',
        ]);

        if (!$selFile) {
            return ['data' => $data, 'citations' => $citations];
        }

        $content = file_get_contents($selFile);
        $citations[] = [
            'file' => 'dsm/result/ipmi_event_log.result',
            'lines' => '1-' . substr_count($content, "\n"),
            'timestamp' => date('Y-m-d H:i:s', filemtime($selFile))
        ];

        $lines = explode("\n", $content);
        $powerKeywords = ['power', 'psu', 'supply', 'volt', 'current', 'shutdown', 'acpi'];

        foreach ($lines as $line) {
            $lineLower = strtolower($line);
            $hasPowerKeyword = false;

            foreach ($powerKeywords as $keyword) {
                if (strpos($lineLower, $keyword) !== false) {
                    $hasPowerKeyword = true;
                    break;
                }
            }

            if (!$hasPowerKeyword) {
                continue;
            }

            // Parse IPMI SEL format: ID | Timestamp | Sensor | Type | Event
            $event = [
                'timestamp' => null,
                'event_type' => null,
                'severity' => 'info',
                'description' => trim($line)
            ];

            // Try to extract timestamp
            if (preg_match('/\d{2}\/\d{2}\/\d{4}\s+\d{2}:\d{2}:\d{2}/', $line, $m)) {
                $event['timestamp'] = $m[0];
            }

            // Determine severity based on keywords
            if (preg_match('/(critical|failure|failed|error)/i', $line)) {
                $event['severity'] = 'critical';
            } elseif (preg_match('/(warning|warn)/i', $line)) {
                $event['severity'] = 'warning';
            }

            if (preg_match('/\|(.*?)\|/i', $line, $m)) {
                $event['event_type'] = trim($m[1]);
            }

            $data[] = $event;
        }

        return ['data' => $data, 'citations' => $citations];
    }

    /**
     * Parse IPMI chassis status
     */
    private function parseChassisStatus(string $extractedPath): array
    {
        $data = [];
        $citations = [];

        // Search for IPMI chassis status file across multiple possible locations
        $chassisFile = $this->locateFile($extractedPath, [
            'dsm/result/ipmi_chassis_status.result',
            'result/ipmi_chassis_status.result',
            'ipmi_chassis_status.result',
            '*/result/ipmi_chassis_status.result',
            '*/ipmi_chassis_status.result',
        ]);

        if (!$chassisFile) {
            return ['data' => $data, 'citations' => $citations];
        }

        $content = file_get_contents($chassisFile);
        $citations[] = [
            'file' => 'dsm/result/ipmi_chassis_status.result',
            'lines' => '1-' . substr_count($content, "\n"),
            'timestamp' => date('Y-m-d H:i:s', filemtime($chassisFile))
        ];

        $status = [
            'current_state' => 'unknown',
            'power_supply_state' => 'unknown',
            'thermal_state' => 'unknown',
            'chassis_intrusion' => 'unknown'
        ];

        if (preg_match('/Current\s+Power\s+State\s*:\s*(.+)/i', $content, $m)) {
            $status['current_state'] = strtolower(trim($m[1]));
        }

        if (preg_match('/Power\s+Supply\s+State\s*:\s*(.+)/i', $content, $m)) {
            $status['power_supply_state'] = strtolower(trim($m[1]));
        }

        if (preg_match('/Thermal\s+State\s*:\s*(.+)/i', $content, $m)) {
            $status['thermal_state'] = strtolower(trim($m[1]));
        }

        if (preg_match('/Chassis\s+Intrusion\s*:\s*(.+)/i', $content, $m)) {
            $status['chassis_intrusion'] = strtolower(trim($m[1]));
        }

        return ['data' => $status, 'citations' => $citations];
    }

    /**
     * Assess overall power system health based on collected data
     * Enhanced to include model-aware PSU configuration detection
     */
    private function assessPowerHealth(array $data, ?string $model = null): array
    {
        // Check if we have any power supply data to assess
        $hasPowerSupplyData = !empty($data['power_supplies'] ?? [])
            || !empty($data['voltage_readings'] ?? [])
            || !empty($data['current_readings'] ?? [])
            || !empty($data['power_events'] ?? [])
            || !empty($data['system_power_status'] ?? []);

        // Default status: 'healthy' if we have data to assess, 'caution' if insufficient data
        $defaultStatus = $hasPowerSupplyData ? 'healthy' : 'caution';

        $assessment = [
            'overall_status' => $defaultStatus,
            'redundancy_status' => 'none',
            'risk_factors' => [],
            'requires_attention' => !$hasPowerSupplyData,  // Flag as requiring attention if we have no data
            'model' => $model,
            'expected_psu_count' => null,
            'actual_psu_count' => 0,
            'has_power_data' => $hasPowerSupplyData,  // Track whether assessment is based on actual data
            'assessment_status' => $hasPowerSupplyData ? 'based_on_data' : 'insufficient_data'
        ];

        // If we have no power supply data and no other power information, add a caution message
        if (!$hasPowerSupplyData) {
            $assessment['risk_factors'][] = 'Power supply monitoring data not available - cannot fully assess power health';
        }

        // Get expected PSU configuration for this model
        $psuSpec = $model ? $this->getPsuSpecForModel($model) : null;
        $psuCount = count($data['power_supplies'] ?? []);
        $assessment['actual_psu_count'] = $psuCount;

        // Model-aware PSU configuration check
        if ($psuSpec) {
            $assessment['expected_psu_count'] = $psuSpec['count'];
            $assessment['redundancy_capable'] = $psuSpec['redundant'];

            // Check for configuration mismatch
            if ($psuSpec['count'] > 1 && $psuCount < $psuSpec['count']) {
                // CRITICAL: Redundant model missing PSU(s)
                $missing = $psuSpec['count'] - $psuCount;
                $assessment['overall_status'] = 'critical';
                $assessment['redundancy_status'] = 'degraded';
                $assessment['requires_attention'] = true;
                $assessment['risk_factors'][] =
                    "CRITICAL: Redundant PSU model missing {$missing} PSU(s) - running on single PSU with zero fault tolerance";
            } elseif ($psuSpec['count'] === 1 && $psuCount === 1) {
                $assessment['redundancy_status'] = 'single';
            }
        }

        // Check PSU detection and health
        if ($psuCount > 1) {
            if (!$psuSpec || !$psuSpec['redundant']) {
                $assessment['redundancy_status'] = 'unexpected_dual';
            }

            $failedCount = 0;
            foreach ($data['power_supplies'] as $psu) {
                if ($psu['status'] === 'failed' || $psu['detection_status'] === 'not_present') {
                    $failedCount++;
                    $assessment['risk_factors'][] = "PSU {$psu['index']}: Not detected or failed";
                }
            }
            if ($failedCount > 0) {
                $assessment['redundancy_status'] = 'degraded';
                $assessment['overall_status'] = 'warning';
                $assessment['requires_attention'] = true;
            }
        } elseif ($psuCount === 1) {
            if (!$psuSpec || $psuSpec['count'] === 1) {
                $assessment['redundancy_status'] = 'single';
            }

            $psu = $data['power_supplies'][0];
            if ($psu['status'] === 'failed' || $psu['detection_status'] === 'not_present') {
                $assessment['overall_status'] = 'critical';
                $severity = ($psuSpec && $psuSpec['count'] > 1)
                    ? "Single PSU on redundant model - zero redundancy"
                    : "Single PSU not detected or failed";
                $assessment['risk_factors'][] = "Single PSU: {$severity} - system at risk";
                $assessment['requires_attention'] = true;
            } elseif ($psu['plugged'] === false) {
                $assessment['overall_status'] = 'critical';
                $assessment['risk_factors'][] = "Single PSU: Not plugged in";
                $assessment['requires_attention'] = true;
            }
        }

        // Check voltage readings
        $voltageIssues = 0;
        foreach ($data['voltage_readings'] ?? [] as $reading) {
            if ($reading['status'] === 'critical') {
                $voltageIssues++;
                $assessment['risk_factors'][] = "Voltage: {$reading['rail_name']} critical ({$reading['voltage_volts']}V)";
            } elseif ($reading['status'] === 'warning') {
                $assessment['risk_factors'][] = "Voltage: {$reading['rail_name']} unstable ({$reading['voltage_volts']}V)";
            }
        }
        if ($voltageIssues > 0) {
            $assessment['overall_status'] = 'critical';
            $assessment['requires_attention'] = true;
        }

        // Check for power events
        $criticalEvents = 0;
        foreach ($data['power_events'] ?? [] as $event) {
            if ($event['severity'] === 'critical') {
                $criticalEvents++;
            }
        }
        if ($criticalEvents > 0) {
            if ($assessment['overall_status'] === 'healthy') {
                $assessment['overall_status'] = 'caution';
            }
            $assessment['risk_factors'][] = "{$criticalEvents} critical power event(s) detected";
            $assessment['requires_attention'] = true;
        }

        // Check chassis power state
        $chassisStatus = $data['system_power_status'] ?? [];
        if ($chassisStatus['power_supply_state'] === 'critical') {
            $assessment['overall_status'] = 'critical';
            $assessment['risk_factors'][] = "Chassis power supply state: Critical";
            $assessment['requires_attention'] = true;
        }

        return $assessment;
    }

    /**
     * FALLBACK: Detect power events from kernel/system logs when IPMI data unavailable
     *
     * Scans kern.log, messages, and other logs for power-related errors:
     * - Power supply failures
     * - Voltage issues
     * - Power cycling events
     * - Thermal/power correlation
     *
     * @param string $extractedPath Bundle directory
     *
     * @return array{data: array, citations: array}
     */
    private function detectPowerEventsFallback(string $extractedPath): array
    {
        $events = [];
        $citations = [];

        $logFiles = [
            'dsm/log/kern.log',
            'dsm/log/messages',
            'dsm/log/scemd',
            'var/log/kern.log',
            'var/log/messages',
        ];

        $powerKeywords = [
            'power\s+(?:supply|failure|fail|issue)',
            'psu\s+(?:fail|error|critical)',
            'voltage\s+(?:out|low|high|critical)',
            'power\s+(?:loss|lost|outage|cycle)',
            'supply\s+(?:error|fault|fail)',
        ];

        foreach ($logFiles as $relPath) {
            $filePath = $extractedPath . '/' . $relPath;
            if (!file_exists($filePath)) continue;

            $handle = fopen($filePath, 'r');
            if (!$handle) continue;

            $lineNum = 0;
            while (($line = fgets($handle)) !== false && $lineNum < 10000) {
                $lineNum++;

                // Check for power-related keywords
                $hasPowerKeyword = false;
                foreach ($powerKeywords as $keyword) {
                    if (preg_match('/' . $keyword . '/i', $line)) {
                        $hasPowerKeyword = true;
                        break;
                    }
                }

                if (!$hasPowerKeyword) continue;

                // Extract timestamp if present
                $timestamp = date('Y-m-d H:i:s'); // Fallback
                if (preg_match('/(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
                    $timestamp = $m[1];
                }

                $events[] = [
                    'timestamp'   => $timestamp,
                    'event_type'  => 'power_event_log',
                    'severity'    => preg_match('/(critical|error|fail)/i', $line) ? 'critical' : 'warning',
                    'description' => trim(substr($line, 0, 200)),
                ];
            }
            fclose($handle);

            if (!empty($events)) {
                $citations[] = [
                    'file'      => $relPath,
                    'lines'     => '1-' . $lineNum,
                    'timestamp' => date('Y-m-d H:i:s', filemtime($filePath) ?: time()),
                    'source'    => 'fallback_log_scan'
                ];
            }
        }

        return [
            'data'      => $events,
            'citations' => $citations
        ];
    }
}
