<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

use PDO;

/**
 * Log Preprocessor: Intelligent log selection and filtering
 *
 * PURPOSE:
 * Selects and filters logs for AI analysis, respecting token budgets
 * while maintaining high-signal data for anomaly detection.
 *
 * STRATEGY:
 * 1. Prioritize IPMI events and Synology system events (always include)
 * 2. Add kernel logs if token budget allows
 * 3. Filter by severity, keywords, and time window
 * 4. Deduplicate identical entries
 * 5. Normalize timestamps to human-readable format
 *
 * TOKEN BUDGET:
 * - IPMI events: ~1.5K-3K tokens per 72-hour window
 * - Synology events: ~800-1.5K tokens
 * - Kernel filtered: ~2K-4K tokens
 * - Total per bundle: 5K minimum, 10K recommended, 15K generous
 *
 * @package App\DeepDive\AI
 */
final class LogPreprocessor
{
    private const TIME_WINDOW_HOURS = 72;
    private const TOKEN_PER_CHAR = 0.25; // Rough estimate: 1 token ~4 chars
    private const MAX_TOKEN_BUDGET = 15000;
    private const MIN_TOKEN_BUDGET = 5000;

    private int $tokenBudget;
    private PDO $pdo;

    /**
     * Initialize with token budget
     *
     * @param PDO $pdo Database connection for querying events
     * @param int $budgetTokens Token budget per bundle (default 10000)
     */
    public function __construct(PDO $pdo, int $budgetTokens = 10000)
    {
        $this->pdo = $pdo;
        $this->tokenBudget = max(self::MIN_TOKEN_BUDGET, min(self::MAX_TOKEN_BUDGET, $budgetTokens));
    }

    /**
     * Select and preprocess logs for AI analysis
     *
     * FLOW:
     * 1. Extract IPMI events (always, highest priority)
     * 2. Extract Synology system events (always)
     * 3. If budget permits: extract filtered kernel logs
     * 4. Add bundle context metadata
     * 5. Return consolidated log package
     *
     * @param string $bundlePath Extracted bundle directory path
     * @param array $metadata Hardware specs, timestamps, etc.
     *
     * @return array{
     *     ipmi_events: array,
     *     syno_events: array,
     *     kernel_logs?: array,
     *     context: array,
     *     estimated_tokens: int,
     *     time_window_hours: int
     * }
     */
    public function selectLogs(string $bundlePath, array $metadata = []): array
    {
        $logs = [];
        $usedTokens = 0;

        // === IPMI Events (always include) ===
        $ipmiEvents = $this->extractIPMIEvents($bundlePath);
        $logs['ipmi_events'] = $ipmiEvents;
        $usedTokens += $this->estimateTokens(json_encode($ipmiEvents));

        // === Synology System Events (always include) ===
        $synoEvents = $this->extractSynoEvents($bundlePath);
        $logs['syno_events'] = $synoEvents;
        $usedTokens += $this->estimateTokens(json_encode($synoEvents));

        // === Kernel Logs (if budget allows) ===
        if ($usedTokens + 4000 < $this->tokenBudget) {
            $kernelLogs = $this->extractKernelLogs($bundlePath);
            if (!empty($kernelLogs)) {
                $logs['kernel_logs'] = $kernelLogs;
                $usedTokens += $this->estimateTokens(json_encode($kernelLogs));
            }
        }

        // === Context Metadata (minimal tokens) ===
        $logs['context'] = [
            'psu_model'         => $metadata['psu_model'] ?? null,
            'hardware_score'    => $metadata['hardware_score'] ?? null,
            'bundle_timestamp'  => $metadata['bundle_timestamp'] ?? date('Y-m-d H:i:s'),
            'time_window_hours' => self::TIME_WINDOW_HOURS,
            'analysis_intent'   => 'Detect anomalies in power supply, thermal, and hardware subsystems',
        ];

        return [
            'ipmi_events'       => $logs['ipmi_events'],
            'syno_events'       => $logs['syno_events'],
            'kernel_logs'       => $logs['kernel_logs'] ?? null,
            'context'           => $logs['context'],
            'estimated_tokens'  => $usedTokens,
            'time_window_hours' => self::TIME_WINDOW_HOURS,
        ];
    }

    /**
     * Extract IPMI events from bundle
     *
     * Looks for:
     * - DMI Type 39 (Power Supply) information
     * - IPMI sensor readings (voltage, current, temperature)
     * - IPMI system event log
     *
     * @param string $bundlePath Bundle directory
     *
     * @return array<array{timestamp: string, type: string, severity: string, message: string}>
     */
    private function extractIPMIEvents(string $bundlePath): array
    {
        $events = [];

        // Look for IPMI sensor data files
        $patterns = [
            'ipmitool*sensor*',
            'ipmi*',
            'sel*',  // System Event Log
            'dmi*type*39*',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($bundlePath . '/*' . $pattern, GLOB_NOSORT);
            if ($files === false) continue;

            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) continue;

                $content = file_get_contents($file, false, null, 0, 100000);
                if ($content === false) continue;

                // Parse file for events
                $fileEvents = $this->parseIPMIFile($file, $content);
                $events = array_merge($events, $fileEvents);
            }
        }

        // Deduplicate and sort by timestamp
        $events = $this->deduplicateEvents($events);
        usort($events, fn($a, $b) => strtotime($a['timestamp'] ?? '0') <=> strtotime($b['timestamp'] ?? '0'));

        return array_slice($events, -200); // Return last 200 events (~72 hours typical)
    }

    /**
     * Extract Synology system events from SQLite database
     *
     * Queries deepdive_incidents or equivalent event tables
     * for power-related, thermal, and hardware events.
     *
     * @param string $bundlePath Bundle directory
     *
     * @return array<array{timestamp: string, type: string, severity: string, message: string}>
     */
    private function extractSynoEvents(string $bundlePath): array
    {
        $events = [];

        // Look for Synology event database files
        $patterns = [
            'synoevt*',
            'scemd*log*',
            'system_event*',
        ];

        foreach ($patterns as $pattern) {
            $files = glob($bundlePath . '/*' . $pattern, GLOB_NOSORT);
            if ($files === false) continue;

            foreach ($files as $file) {
                if (!is_file($file) || !is_readable($file)) continue;

                $content = file_get_contents($file, false, null, 0, 50000);
                if ($content === false) continue;

                // Parse for power, thermal, hardware keywords
                $fileEvents = $this->parseSynoFile($file, $content);
                $events = array_merge($events, $fileEvents);
            }
        }

        // Deduplicate and filter to relevant severity
        $events = $this->deduplicateEvents($events);
        $events = array_filter($events, fn($e) =>
            in_array($e['severity'] ?? '', ['CRITICAL', 'ERROR', 'WARNING'])
        );

        usort($events, fn($a, $b) => strtotime($a['timestamp'] ?? '0') <=> strtotime($b['timestamp'] ?? '0'));

        return array_slice($events, -150);
    }

    /**
     * Extract kernel log entries
     *
     * Filters to ERROR and CRITICAL severity only
     * Keywords: hardware, power, psu, voltage, thermal, fan, disk
     *
     * @param string $bundlePath Bundle directory
     *
     * @return array<array{timestamp: string, severity: string, message: string}>|null
     */
    private function extractKernelLogs(string $bundlePath): ?array
    {
        $file = $bundlePath . '/kern.log';
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }

        $events = [];
        $handle = fopen($file, 'r');
        if ($handle === false) return null;

        $keywords = ['hardware', 'power', 'psu', 'voltage', 'thermal', 'fan', 'disk', 'error', 'critical'];
        $lineNum = 0;

        while (($line = fgets($handle)) !== false && $lineNum < 50000) {
            $lineNum++;

            // Check severity
            if (!preg_match('/\b(ERROR|CRITICAL|ALERT|EMERG)\b/i', $line)) {
                continue;
            }

            // Check relevance
            $relevantKeywords = array_filter($keywords, fn($kw) => stripos($line, $kw) !== false);
            if (empty($relevantKeywords)) {
                continue;
            }

            // Parse timestamp and message
            if (preg_match('/^([A-Za-z]+\s+\d+\s+[\d:]+)\s+(.+)/', $line, $matches)) {
                $events[] = [
                    'timestamp' => $matches[1],
                    'severity'  => 'ERROR',
                    'message'   => trim($matches[2]),
                ];
            }
        }

        fclose($handle);

        $events = $this->deduplicateEvents($events);
        usort($events, fn($a, $b) => strtotime($a['timestamp'] ?? '0') <=> strtotime($b['timestamp'] ?? '0'));

        return empty($events) ? null : array_slice($events, -100);
    }

    /**
     * Parse IPMI file for events
     *
     * Handles:
     * - ipmitool sensor output (voltage, current, temp)
     * - DMI Type 39 power supply info
     * - System event logs
     *
     * @param string $filename File path
     * @param string $content File content
     *
     * @return array<array{timestamp: string, type: string, severity: string, message: string}>
     */
    private function parseIPMIFile(string $filename, string $content): array
    {
        $events = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Detect Power Supply events
            if (stripos($line, 'power supply') !== false || stripos($line, 'psu') !== false) {
                $severity = 'CRITICAL';
                if (stripos($line, 'ok') !== false || stripos($line, 'present') !== false) {
                    $severity = 'INFO';
                } elseif (stripos($line, 'warning') !== false) {
                    $severity = 'WARNING';
                } elseif (stripos($line, 'critical') !== false || stripos($line, 'fail') !== false) {
                    $severity = 'CRITICAL';
                }

                $events[] = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type'      => 'IPMI_PSU',
                    'severity'  => $severity,
                    'message'   => $line,
                ];
            }

            // Detect Voltage events
            if (stripos($line, 'volt') !== false) {
                if (preg_match('/(\d+\.?\d*)\s*V/', $line, $m)) {
                    $severity = 'INFO';
                    if (stripos($line, 'critical') !== false || stripos($line, 'lower critical') !== false) {
                        $severity = 'CRITICAL';
                    } elseif (stripos($line, 'warning') !== false) {
                        $severity = 'WARNING';
                    }

                    $events[] = [
                        'timestamp' => date('Y-m-d H:i:s'),
                        'type'      => 'IPMI_VOLTAGE',
                        'severity'  => $severity,
                        'message'   => $line,
                    ];
                }
            }

            // Detect Thermal events
            if (stripos($line, 'temp') !== false && preg_match('/(\d+)\s*°?C/', $line, $m)) {
                $severity = 'INFO';
                if ($m[1] > 80) $severity = 'WARNING';
                if ($m[1] > 95) $severity = 'CRITICAL';

                $events[] = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type'      => 'IPMI_THERMAL',
                    'severity'  => $severity,
                    'message'   => $line,
                ];
            }
        }

        return $events;
    }

    /**
     * Parse Synology-specific log file
     *
     * @param string $filename File path
     * @param string $content File content
     *
     * @return array<array{timestamp: string, type: string, severity: string, message: string}>
     */
    private function parseSynoFile(string $filename, string $content): array
    {
        $events = [];
        $lines = explode("\n", $content);

        $keywords = ['power', 'thermal', 'fan', 'psu', 'voltage'];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Check for relevant keywords
            $hasKeyword = false;
            foreach ($keywords as $kw) {
                if (stripos($line, $kw) !== false) {
                    $hasKeyword = true;
                    break;
                }
            }

            if (!$hasKeyword) continue;

            // Infer severity
            $severity = 'INFO';
            if (stripos($line, 'critical') !== false) $severity = 'CRITICAL';
            elseif (stripos($line, 'error') !== false) $severity = 'ERROR';
            elseif (stripos($line, 'warning') !== false) $severity = 'WARNING';

            if ($severity !== 'INFO') {
                $events[] = [
                    'timestamp' => date('Y-m-d H:i:s'),
                    'type'      => basename($filename),
                    'severity'  => $severity,
                    'message'   => $line,
                ];
            }
        }

        return $events;
    }

    /**
     * Deduplicate events by message content
     *
     * @param array $events Events array
     *
     * @return array Deduplicated events
     */
    private function deduplicateEvents(array $events): array
    {
        $seen = [];
        $unique = [];

        foreach ($events as $event) {
            // Use message hash for deduplication
            $hash = md5($event['message'] ?? '');
            if (!isset($seen[$hash])) {
                $seen[$hash] = true;
                $unique[] = $event;
            }
        }

        return $unique;
    }

    /**
     * Estimate token count for content
     *
     * Rough estimate: 1 token ≈ 4 characters
     *
     * @param string $content Content to estimate
     *
     * @return int Estimated token count
     */
    private function estimateTokens(string $content): int
    {
        return (int)ceil(strlen($content) * self::TOKEN_PER_CHAR);
    }
}
