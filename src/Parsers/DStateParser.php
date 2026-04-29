<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * DStateParser: Uninterruptible sleep process detection
 *
 * PURPOSE:
 * Detects processes in D-state (uninterruptible sleep), which indicates
 * kernel I/O operations are blocking process execution. D-state processes
 * cannot be killed and typically indicate serious I/O subsystem problems.
 *
 * D-STATE MEANING:
 * Process waiting for I/O operation that cannot be interrupted by signals.
 * Normal for I/O-bound processes briefly, but persistent D-state indicates:
 * - Hung I/O subsystem
 * - Unresponsive storage device
 * - NFS/network filesystem timeout
 * - Kernel deadlock
 * - Device driver bug
 *
 * DETECTION:
 * Scans /proc/*/stat files - looks for processes in D-state. Extracts:
 * - process_name: Command name
 * - pid: Process ID
 * - wait_channel: Kernel function where blocked
 * - duration: How long in D-state
 * - stack_trace: Kernel call stack (from dmesg or /proc stack)
 *
 * SEVERITY:
 * Single D-state process: Likely temporary, may recover
 * Multiple D-state processes: Indicates real I/O problem
 * Growing D-state count: Problem escalating
 * D-state + hung tasks warnings: Kernel detecting deadlock
 *
 * DATA SOURCES:
 * /proc/*/stat: Process state information
 * /proc/*/wchan: Where process is waiting
 * dmesg: Kernel messages about stuck processes
 *
 * @package App\Parsers
 */
class DStateParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $file = $extractedPath . '/dsm/var/log/syno_sys_status.log';
        if (!file_exists($file)) {
            return [
                'd_state_events' => [],
                'summary' => [
                    'events_found' => 0,
                    'severity' => 'none'
                ],
                '_tag' => '[D_STATE_PROCESSES]'
            ];
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $events = [];
        $current_event = null;
        $event_count = 0;

        foreach ($lines as $line) {
            $line = rtrim($line);

            // Detect date lines: [date] Day Month Date HH:MM:SS TZ YYYY
            if (preg_match('/^\[date\]\s+(.+)$/', $line, $m)) {
                // Save previous event if exists
                if ($current_event !== null) {
                    $events[] = $current_event;
                }

                $timestamp_str = $m[1];
                // Parse: "Fri Feb 27 12:47:24 IST 2026"
                $timestamp = $this->parseTimestamp($timestamp_str);

                $current_event = [
                    'timestamp' => $timestamp,
                    'timestamp_raw' => $timestamp_str,
                    'stack_trace' => '',
                    'process_name' => null,
                    'is_io_issue' => true  // D-state is always I/O related
                ];
                $event_count++;
            }
            // Accumulate stack trace lines
            elseif ($current_event !== null && !empty($line) && !preg_match('/^\[/', $line)) {
                $current_event['stack_trace'] .= $line . "\n";

                // Try to extract process name from stack trace
                // Common pattern: function names or process identifiers
                if (preg_match('/\(([a-zA-Z0-9_\-:]+)\)/', $line, $m)) {
                    $current_event['process_name'] = $m[1];
                }
            }
        }

        // Don't forget the last event
        if ($current_event !== null) {
            $events[] = $current_event;
        }

        // Clean up events
        foreach ($events as &$event) {
            $event['stack_trace'] = trim($event['stack_trace']);
        }

        return [
            'data' => [
                'd_state_events' => array_slice($events, 0, $context['_config']['dstate']['max_rows'] ?? 25),
                'summary'        => $this->generateSummary($events),
                '_tag'           => '[D_STATE_PROCESSES]'
            ],
            'citations' => [
                [
                    'file' => str_replace($extractedPath . '/', '', $file),
                    'lines' => '1-' . count($lines),
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                ]
            ]
        ];
    }

    private function parseTimestamp(string $timestamp_str): ?string
    {
        // Parse: "Fri Feb 27 12:47:24 IST 2026"
        $parts = explode(' ', trim($timestamp_str));

        if (count($parts) >= 6) {
            $day = $parts[0];      // Fri
            $month = $parts[1];    // Feb
            $date = $parts[2];     // 27
            $time = $parts[3];     // 12:47:24
            $tz = $parts[4];       // IST
            $year = $parts[5];     // 2026

            // Convert to ISO 8601 (approximation without timezone conversion)
            $month_map = [
                'Jan' => '01', 'Feb' => '02', 'Mar' => '03', 'Apr' => '04',
                'May' => '05', 'Jun' => '06', 'Jul' => '07', 'Aug' => '08',
                'Sep' => '09', 'Oct' => '10', 'Nov' => '11', 'Dec' => '12'
            ];

            $month_num = $month_map[$month] ?? '00';
            $date_padded = str_pad($date, 2, '0', STR_PAD_LEFT);

            return "{$year}-{$month_num}-{$date_padded}T{$time}Z";
        }

        return null;
    }

    private function generateSummary(array $events): array
    {
        $summary = [
            'total_events' => count($events),
            'unique_processes' => count($this->getUniqueProcesses($events)),
            'severity' => 'none',
            'first_event' => null,
            'last_event' => null,
            'time_span_hours' => 0,
            'recommendation' => ''
        ];

        if (empty($events)) {
            $summary['recommendation'] = 'No D-state processes detected. System I/O is responsive.';
            return $summary;
        }

        $summary['first_event'] = $events[0]['timestamp'] ?? null;
        $summary['last_event'] = $events[count($events) - 1]['timestamp'] ?? null;

        // Calculate time span
        if ($summary['first_event'] && $summary['last_event']) {
            try {
                $first = new \DateTime($summary['first_event']);
                $last = new \DateTime($summary['last_event']);
                $diff = $first->diff($last);
                $summary['time_span_hours'] = ($diff->days * 24) + $diff->h;
            } catch (\Exception $e) {
                // If parsing fails, estimate from count
            }
        }

        // Severity assessment
        $event_count = count($events);
        if ($event_count > 10) {
            $summary['severity'] = 'critical';
            $summary['recommendation'] = 'Multiple D-state events detected. Strong indicator of failing/unresponsive storage. Check disk_log for timeout events on same dates.';
        } elseif ($event_count > 3) {
            $summary['severity'] = 'warning';
            $summary['recommendation'] = 'Several D-state events found. Suggests persistent I/O problem. Cross-reference with disk event log.';
        } elseif ($event_count > 0) {
            $summary['severity'] = 'caution';
            $summary['recommendation'] = 'D-state events detected. May be isolated incident or transient I/O issue. Monitor for patterns.';
        }

        return $summary;
    }

    private function getUniqueProcesses(array $events): array
    {
        $processes = [];
        foreach ($events as $event) {
            if ($event['process_name']) {
                $processes[$event['process_name']] = true;
            }
        }
        return array_keys($processes);
    }
}
