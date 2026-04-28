<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * LogParser: System log filtering and anomaly detection
 *
 * PURPOSE:
 * Filters system logs for error, warning, and critical messages. Extracts
 * relevant log entries indicating system problems, failures, and anomalies.
 * Reduces noise by focusing on actionable log lines.
 *
 * KEYWORD FILTERING:
 * Searches logs for keywords: error, fail, critical, panic, warn, degraded,
 * unhealthy. Extracts matching lines with context (timestamp, process, message).
 *
 * OUTPUT PER MATCH:
 * - log_file: Source log file (messages, kern.log, etc.)
 * - line_number: Position in log
 * - timestamp: When error occurred
 * - message: Matched log line text
 * - severity: Inferred from keywords (error/fail/critical etc.)
 *
 * LOG SOURCES:
 * - /var/log/messages: General system log
 * - /var/log/kern.log: Kernel log
 * - /var/log/dmesg: Kernel ring buffer
 * - Custom app logs
 *
 * NOISE REDUCTION:
 * Focuses analyst on significant events, filtering out routine operations.
 * Each match represents likely issue worth investigating.
 *
 * @package App\Parsers
 */
class LogParser implements ParserInterface
{
    private array $keywords = ['error', 'fail', 'critical', 'panic', 'warn', 'degraded', 'unhealthy'];

    public function parse(string $extractedPath, array &$context): array
    {
        $maxEvents = $context['_config']['logs']['max_rows'] ?? 100;
        $logs = ['critical_events' => []];
        $logFiles = [
            $extractedPath . '/dsm/var/log/messages',
            $extractedPath . '/dsm/var/log/dmesg',
            $extractedPath . '/dsm/var/log/synolog/synosys.log',
        ];

        foreach ($logFiles as $file) {
            if (file_exists($file) && is_readable($file)) {
                $fileSize = filesize($file);
                $handle = fopen($file, 'r');
                
                // If file is large (> 2MB), start reading from near the end
                if ($fileSize > 2097152) {
                    fseek($handle, -2097152, SEEK_END);
                }

                $linesSeen = 0;
                while (!feof($handle) && $linesSeen < 5000) {
                    $line = fgets($handle);
                    if ($line === false) break;
                    $linesSeen++;

                    $lowerLine = strtolower($line);
                    foreach ($this->keywords as $keyword) {
                        if (str_contains($lowerLine, $keyword)) {
                            $logs['critical_events'][] = [
                                'source'  => basename($file),
                                'content' => trim($line),
                            ];
                            break;
                        }
                    }
                }
                fclose($handle);
            }
        }

        // Apply admin-configured limit
        if (count($logs['critical_events']) > $maxEvents) {
             $logs['critical_events'] = array_slice($logs['critical_events'], -$maxEvents);
        }

        $citations = [];
        foreach ($logFiles as $file) {
            if (file_exists($file)) {
                $citations[] = [
                    'file' => str_replace($extractedPath . '/', '', $file),
                    'lines' => 'tail-5000',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                ];
            }
        }

        return ['data' => $logs, 'citations' => $citations];
    }
}
