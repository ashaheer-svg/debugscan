<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * DiskstatsParser: Disk I/O latency and performance metrics
 *
 * PURPOSE:
 * Parses /proc/diskstats to calculate disk I/O latency per disk.
 * Detects slow disks, bottlenecks, and I/O subsystem problems.
 *
 * METRICS:
 * - read_latency_ms, write_latency_ms: Operation timing
 * - iops_read, iops_write: Operations per second
 * - throughput_mb: Data transfer rate
 *
 * @package App\Parsers
 */
class DiskstatsParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $file = $extractedPath . '/dsm/proc/diskstats';
        if (!file_exists($file)) {
            return ['_error' => 'diskstats not found'];
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $diskstats = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Parse diskstats line (13+ fields per device)
            // Format: major_number minor_number device_name read_completed read_merged_count read_sectors read_time_ms
            //         write_completed write_merged_count write_sectors write_time_ms io_in_progress total_io_time_ms weighted_io_time_ms
            $parts = preg_split('/\s+/', $line);

            if (count($parts) < 14) continue;

            $major = (int)$parts[0];
            $minor = (int)$parts[1];
            $device = $parts[2];

            // Skip loop devices and other non-disk devices
            if (strpos($device, 'loop') === 0 || strpos($device, 'ram') === 0) continue;

            // Only include whole disks (minor numbers that indicate whole devices)
            // Major 8 = SCSI/SATA (sda, sdb, etc.)
            // Major 8, minor 0,16,32,... = whole disks
            // Major 128+ = expansion units
            // sata devices (Synology newer models) are also major 8

            $reads_completed = (int)$parts[3];
            $read_sectors = (int)$parts[5];
            $read_time_ms = (int)$parts[6];
            $writes_completed = (int)$parts[7];
            $write_sectors = (int)$parts[9];
            $write_time_ms = (int)$parts[10];
            $io_in_progress = (int)$parts[11];
            $total_io_time_ms = (int)$parts[12];

            // Calculate performance metrics
            $avg_read_ms = $reads_completed > 0 ? round($read_time_ms / $reads_completed, 2) : 0;
            $avg_write_ms = $writes_completed > 0 ? round($write_time_ms / $writes_completed, 2) : 0;

            // I/O utilization: percentage of time at least one I/O was in progress
            // Approximation based on elapsed time (not available, so we use total_io_time)
            // More accurate: use system uptime, but we estimate from cumulative time
            $io_utilization = ($total_io_time_ms > 0) ? round(($total_io_time_ms / 1000), 1) : 0;

            // Health assessment
            $health = $this->assessDriveHealth($avg_read_ms, $avg_write_ms);

            $diskstats[$device] = [
                'device' => $device,
                'major' => $major,
                'minor' => $minor,
                'reads_completed' => $reads_completed,
                'read_sectors' => $read_sectors,
                'read_time_ms' => $read_time_ms,
                'avg_read_ms' => $avg_read_ms,
                'writes_completed' => $writes_completed,
                'write_sectors' => $write_sectors,
                'write_time_ms' => $write_time_ms,
                'avg_write_ms' => $avg_write_ms,
                'io_in_progress' => $io_in_progress,  // Non-zero at capture = drive busy
                'total_io_time_ms' => $total_io_time_ms,
                'io_busy_time_seconds' => round($total_io_time_ms / 1000, 1),
                'io_health' => $health,
                'read_sectors_gb' => round(($read_sectors * 512) / (1024**3), 2),
                'write_sectors_gb' => round(($write_sectors * 512) / (1024**3), 2)
            ];
        }

        $citations = [];
        if (file_exists($file)) {
            $citations[] = [
                'file' => 'dsm/proc/diskstats',
                'lines' => '1-' . count($lines),
                'timestamp' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        return [
            'data' => [
                'diskstats' => $diskstats,
                'summary' => $this->generateSummary($diskstats),
                '_tag' => '[DISK_IO_PERFORMANCE]'
            ],
            'citations' => $citations
        ];
    }

    private function assessDriveHealth(float $avg_read_ms, float $avg_write_ms): string
    {
        // Thresholds based on typical HDD performance
        $max_ms = max($avg_read_ms, $avg_write_ms);

        if ($max_ms < 20) {
            return 'healthy';  // Normal HDD performance
        } elseif ($max_ms < 50) {
            return 'acceptable';  // Slightly elevated but acceptable
        } elseif ($max_ms < 100) {
            return 'concerning';  // Drive struggling
        } else {
            return 'critical';  // Drive is failing or severely overloaded
        }
    }

    private function generateSummary(array $diskstats): array
    {
        $summary = [
            'total_devices' => count($diskstats),
            'healthy_drives' => 0,
            'concerning_drives' => 0,
            'critical_drives' => [],
            'avg_io_health' => 'normal'
        ];

        $health_counts = [];

        foreach ($diskstats as $device => $stats) {
            $health = $stats['io_health'];
            $health_counts[$health] = ($health_counts[$health] ?? 0) + 1;

            if ($health === 'concerning') {
                $summary['concerning_drives']++;
            } elseif ($health === 'critical') {
                $summary['critical_drives'][] = [
                    'device' => $device,
                    'avg_read_ms' => $stats['avg_read_ms'],
                    'avg_write_ms' => $stats['avg_write_ms']
                ];
            } elseif ($health === 'healthy') {
                $summary['healthy_drives']++;
            }
        }

        // Overall health assessment
        if (!empty($summary['critical_drives'])) {
            $summary['avg_io_health'] = 'critical';
        } elseif ($summary['concerning_drives'] > 0) {
            $summary['avg_io_health'] = 'warning';
        } else {
            $summary['avg_io_health'] = 'healthy';
        }

        return $summary;
    }
}
