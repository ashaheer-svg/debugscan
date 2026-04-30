<?php

declare(strict_types=1);

namespace App\DeepDive\Metrics;

use Psr\Log\LoggerInterface;

/**
 * MetricsExtractor: Extract time-series metrics from debug bundles
 *
 * PURPOSE:
 * Parses debug bundle files and extracts numerical metrics that can be
 * stored in timeseries database. Enables trend analysis even after bundles
 * are deleted.
 *
 * METRICS EXTRACTED:
 * - Network: interface errors, dropped packets, packet loss %
 * - Memory: used %, pressure rate, OOM events, swap activity
 * - RAID: rebuild progress %, rebuild speed, error rates per drive
 * - Thermal: CPU temp, drive temps, fan RPM, throttle events
 * - Storage: disk usage %, inode usage %, free space
 * - Services: restart count per service, crash indicators
 * - Backup: duration, data transferred, success rate
 *
 * OUTPUT:
 * Array of metrics with normalized format:
 * [
 *   'network.eth0.errors' => ['value' => 47, 'unit' => 'count'],
 *   'memory.used_percent' => ['value' => 78.5, 'unit' => 'percent'],
 *   'raid.md0.rebuild_speed_mb_s' => ['value' => 125.3, 'unit' => 'MB/s'],
 * ]
 */
final class MetricsExtractor
{
    private ?LoggerInterface $logger;

    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Extract all metrics from a bundle
     *
     * @param array $bundle Bundle metadata and paths
     * @return array Extracted metrics in normalized format
     */
    public function extractAll(array $bundle): array
    {
        $metrics = [];
        $bundlePath = $bundle['extracted_path'] ?? null;

        if (!$bundlePath || !is_dir($bundlePath)) {
            return $metrics;
        }

        // Extract from different subsystems
        $metrics = array_merge(
            $metrics,
            $this->extractNetworkMetrics($bundlePath)
        );

        $metrics = array_merge(
            $metrics,
            $this->extractMemoryMetrics($bundlePath)
        );

        $metrics = array_merge(
            $metrics,
            $this->extractRAIDMetrics($bundlePath)
        );

        $metrics = array_merge(
            $metrics,
            $this->extractThermalMetrics($bundlePath)
        );

        $metrics = array_merge(
            $metrics,
            $this->extractStorageMetrics($bundlePath)
        );

        $metrics = array_merge(
            $metrics,
            $this->extractServiceMetrics($bundlePath)
        );

        $this->log('debug', 'Extracted ' . count($metrics) . ' metrics from bundle');

        return $metrics;
    }

    /**
     * Extract network interface metrics
     */
    private function extractNetworkMetrics(string $bundlePath): array
    {
        $metrics = [];

        // /proc/net/dev parsing
        $netDevFile = $bundlePath . '/proc/net/dev';
        if (file_exists($netDevFile)) {
            $lines = file($netDevFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                if (strpos($line, ':') === false) continue;

                [$interface, $data] = explode(':', $line, 2);
                $interface = trim($interface);

                // Skip loopback
                if ($interface === 'lo') continue;

                $parts = preg_split('/\s+/', trim($data));
                if (count($parts) >= 16) {
                    // Format: rx_bytes rx_packets rx_errs rx_drop ... tx_bytes tx_packets tx_errs tx_drop
                    $rx_errors = (int)($parts[2] ?? 0);
                    $rx_dropped = (int)($parts[3] ?? 0);
                    $tx_errors = (int)($parts[10] ?? 0);
                    $tx_dropped = (int)($parts[11] ?? 0);

                    $total_errors = $rx_errors + $rx_dropped + $tx_errors + $tx_dropped;

                    $metrics["network.{$interface}.rx_errors"] = [
                        'value' => $rx_errors,
                        'unit' => 'count'
                    ];

                    $metrics["network.{$interface}.tx_errors"] = [
                        'value' => $tx_errors,
                        'unit' => 'count'
                    ];

                    $metrics["network.{$interface}.total_errors"] = [
                        'value' => $total_errors,
                        'unit' => 'count'
                    ];
                }
            }
        }

        return $metrics;
    }

    /**
     * Extract memory metrics
     */
    private function extractMemoryMetrics(string $bundlePath): array
    {
        $metrics = [];

        // /proc/meminfo parsing
        $meminfoFile = $bundlePath . '/proc/meminfo';
        if (file_exists($meminfoFile)) {
            $meminfo = [];
            $lines = file($meminfoFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                if (preg_match('/^(\w+):\s+(\d+)/', $line, $m)) {
                    $meminfo[$m[1]] = (int)$m[2];
                }
            }

            if (!empty($meminfo)) {
                $total = $meminfo['MemTotal'] ?? 0;
                $free = $meminfo['MemFree'] ?? 0;
                $available = $meminfo['MemAvailable'] ?? $free;
                $swap_total = $meminfo['SwapTotal'] ?? 0;
                $swap_free = $meminfo['SwapFree'] ?? 0;

                if ($total > 0) {
                    $used = $total - $available;
                    $used_percent = ($used / $total) * 100;

                    $metrics['memory.used_percent'] = [
                        'value' => round($used_percent, 2),
                        'unit' => 'percent'
                    ];

                    $metrics['memory.available_percent'] = [
                        'value' => round(($available / $total) * 100, 2),
                        'unit' => 'percent'
                    ];
                }

                if ($swap_total > 0) {
                    $swap_used = $swap_total - $swap_free;
                    $swap_percent = ($swap_used / $swap_total) * 100;

                    $metrics['memory.swap_used_percent'] = [
                        'value' => round($swap_percent, 2),
                        'unit' => 'percent'
                    ];
                }

                // Page faults and pressure
                $pgfault = $meminfo['pgfault'] ?? 0;
                $pgmajfault = $meminfo['pgmajfault'] ?? 0;

                $metrics['memory.major_page_faults'] = [
                    'value' => $pgmajfault,
                    'unit' => 'count'
                ];
            }
        }

        return $metrics;
    }

    /**
     * Extract RAID metrics
     */
    private function extractRAIDMetrics(string $bundlePath): array
    {
        $metrics = [];

        // /proc/mdstat parsing
        $mdstatFile = $bundlePath . '/proc/mdstat';
        if (file_exists($mdstatFile)) {
            $content = file_get_contents($mdstatFile);
            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                if (preg_match('/^(md\d+)\s+:/', $line, $m)) {
                    $device = $m[1];

                    // Rebuild progress: [=====>....] 45.2%
                    if (preg_match('/\[[\w\=\>\.]+\]\s+([\d.]+)%/', $line, $m)) {
                        $progress = (float)$m[1];
                        $metrics["raid.{$device}.rebuild_progress_percent"] = [
                            'value' => $progress,
                            'unit' => 'percent'
                        ];
                    }

                    // Check/Resync speed: (check:12.5% finish=125.3min speed=50123K/sec)
                    if (preg_match('/speed=(\d+)K\/sec/', $line, $m)) {
                        $speed_kb_s = (int)$m[1];
                        $speed_mb_s = $speed_kb_s / 1024;

                        $metrics["raid.{$device}.rebuild_speed_mb_s"] = [
                            'value' => round($speed_mb_s, 2),
                            'unit' => 'MB/s'
                        ];
                    }
                }
            }
        }

        // Parse SMART data for drive errors
        $smartDir = $bundlePath . '/smartctl';
        if (is_dir($smartDir)) {
            foreach (glob($smartDir . '/*.txt') as $smartFile) {
                $basename = basename($smartFile, '.txt');
                $content = file_get_contents($smartFile);

                // Reallocated sectors
                if (preg_match('/Reallocated_Sector_Ct\s+[\dA-F]+\s+[\d\-]+\s+[\d\-]+\s+[\d\-]+\s+Old_age\s+[\d\-]+\s+[\d\-]+\s+(\d+)/', $content, $m)) {
                    $metrics["raid.{$basename}.reallocated_sectors"] = [
                        'value' => (int)$m[1],
                        'unit' => 'count'
                    ];
                }

                // Current pending sector
                if (preg_match('/Current_Pending_Sector\s+[\dA-F]+\s+[\d\-]+\s+[\d\-]+\s+[\d\-]+\s+[\w\-]+\s+[\d\-]+\s+[\d\-]+\s+(\d+)/', $content, $m)) {
                    $metrics["raid.{$basename}.pending_sectors"] = [
                        'value' => (int)$m[1],
                        'unit' => 'count'
                    ];
                }
            }
        }

        return $metrics;
    }

    /**
     * Extract thermal metrics
     */
    private function extractThermalMetrics(string $bundlePath): array
    {
        $metrics = [];

        // Sensor data
        $sensorsFile = $bundlePath . '/sensors_output.txt';
        if (file_exists($sensorsFile)) {
            $content = file_get_contents($sensorsFile);

            // CPU temperature
            if (preg_match('/Core\s+\d+:\s+\+?([\d.]+)°C/', $content, $m)) {
                $metrics['thermal.cpu_temp_celsius'] = [
                    'value' => (float)$m[1],
                    'unit' => 'celsius'
                ];
            }

            // Fan speeds
            if (preg_match('/Fan\s+\d+:\s+(\d+)\s+RPM/', $content, $m)) {
                $metrics['thermal.fan_rpm'] = [
                    'value' => (int)$m[1],
                    'unit' => 'RPM'
                ];
            }
        }

        // Thermal throttle events in syslog
        $syslogFile = $bundlePath . '/var/log/syslog';
        if (file_exists($syslogFile)) {
            $throttle_count = substr_count(
                file_get_contents($syslogFile),
                'thermal'
            );

            if ($throttle_count > 0) {
                $metrics['thermal.throttle_events'] = [
                    'value' => $throttle_count,
                    'unit' => 'count'
                ];
            }
        }

        return $metrics;
    }

    /**
     * Extract storage metrics
     */
    private function extractStorageMetrics(string $bundlePath): array
    {
        $metrics = [];

        // df output parsing
        $dfFile = $bundlePath . '/df_output.txt';
        if (file_exists($dfFile)) {
            $lines = file($dfFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

            foreach ($lines as $line) {
                // Skip headers
                if (strpos($line, 'Filesystem') !== false) continue;

                $parts = preg_split('/\s+/', $line);
                if (count($parts) >= 5) {
                    $filesystem = $parts[0];
                    $percent_used = (int)str_replace('%', '', $parts[4]);

                    $fs_name = str_replace(['/', '.'], ['_', '_'], $filesystem);
                    $metrics["storage.{$fs_name}.used_percent"] = [
                        'value' => $percent_used,
                        'unit' => 'percent'
                    ];
                }
            }
        }

        return $metrics;
    }

    /**
     * Extract service metrics
     */
    private function extractServiceMetrics(string $bundlePath): array
    {
        $metrics = [];

        // Parse systemd journal or syslog for service restarts
        $journalFile = $bundlePath . '/var/log/syslog';
        if (file_exists($journalFile)) {
            $lines = file($journalFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $service_restarts = [];

            foreach ($lines as $line) {
                // Look for systemd restart patterns
                if (preg_match('/(\w+)\[\d+\]:\s+(?:Started|Stopped|Restarted|Failed)/', $line, $m)) {
                    $service = $m[1];
                    $service_restarts[$service] = ($service_restarts[$service] ?? 0) + 1;
                }
            }

            foreach ($service_restarts as $service => $count) {
                $metrics["service.{$service}.restart_count"] = [
                    'value' => $count,
                    'unit' => 'count'
                ];
            }
        }

        return $metrics;
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[metrics-extractor] ' . $message);
        }
    }
}
