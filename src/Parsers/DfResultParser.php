<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * DfResultParser: Filesystem disk usage statistics
 *
 * PURPOSE:
 * Parses df output to extract per-filesystem usage statistics. Complements
 * VolumeParser with detailed breakdown of each mount point's capacity and usage.
 *
 * OUTPUT PER FILESYSTEM:
 * - filesystem: Device or path
 * - mount_point: Where mounted
 * - total_kb, used_kb, available_kb: Capacity metrics
 * - usage_percent: Utilization percentage
 * - inodes_total, inodes_used, inodes_percent: File count limits
 *
 * @package App\Parsers
 */
class DfResultParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $file = $extractedPath . '/dsm/result/df.result';
        if (!file_exists($file)) {
            return ['_error' => 'df.result not found'];
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $filesystems = [];
        $header_found = false;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Skip header line
            if (preg_match('/^Filesystem\s+/', $line)) {
                $header_found = true;
                continue;
            }

            if (!$header_found) continue;

            // Parse: Filesystem  1K-blocks  Used  Available Use% Mounted on
            // Example: /dev/md0    2385528 1681248    585496  75%  /
            if (preg_match('/^(\S+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(.+)$/', $line, $m)) {
                $device = $m[1];
                $total_1k_blocks = (int)$m[2];
                $used_1k_blocks = (int)$m[3];
                $available_1k_blocks = (int)$m[4];
                $use_percent = (int)$m[5];
                $mountpoint = $m[6];

                // Convert to GB
                $total_gb = round($total_1k_blocks / 1024 / 1024, 2);
                $used_gb = round($used_1k_blocks / 1024 / 1024, 2);
                $available_gb = round($available_1k_blocks / 1024 / 1024, 2);

                $health = $this->assessDiskHealth($use_percent, $mountpoint);

                $filesystems[$mountpoint] = [
                    'device' => $device,
                    'mountpoint' => $mountpoint,
                    'total_1k_blocks' => $total_1k_blocks,
                    'used_1k_blocks' => $used_1k_blocks,
                    'available_1k_blocks' => $available_1k_blocks,
                    'total_gb' => $total_gb,
                    'used_gb' => $used_gb,
                    'available_gb' => $available_gb,
                    'use_percent' => $use_percent,
                    'free_percent' => 100 - $use_percent,
                    'health' => $health
                ];
            }
        }

        return [
            'data' => [
                'filesystems' => $filesystems,
                'summary' => $this->generateSummary($filesystems),
                '_tag' => '[DISK_SPACE_USAGE]'
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

    private function assessDiskHealth(int $use_percent, string $mountpoint): string
    {
        // System partition (/dev/md0 or /) has tighter thresholds
        // because DSM updates need space to unpack
        $is_system = ($mountpoint === '/' || strpos($mountpoint, 'md0') !== false);

        if ($is_system) {
            // System partition critical thresholds
            if ($use_percent > 90) {
                return 'critical';  // Update may fail
            } elseif ($use_percent > 75) {
                return 'warning';  // Getting tight
            } else {
                return 'healthy';
            }
        } else {
            // Data volume thresholds
            // btrfs performance degrades significantly above 85%
            if ($use_percent > 95) {
                return 'critical';  // Almost full
            } elseif ($use_percent > 85) {
                return 'warning';  // btrfs performance affected
            } elseif ($use_percent > 75) {
                return 'caution';   // Monitor closely
            } else {
                return 'healthy';
            }
        }
    }

    private function generateSummary(array $filesystems): array
    {
        $summary = [
            'total_filesystems' => count($filesystems),
            'critical_volumes' => [],
            'warning_volumes' => [],
            'system_partition_status' => null,
            'data_volume_status' => null,
            'overall_health' => 'healthy'
        ];

        foreach ($filesystems as $mountpoint => $fs) {
            if ($fs['health'] === 'critical') {
                $summary['critical_volumes'][] = [
                    'mountpoint' => $mountpoint,
                    'use_percent' => $fs['use_percent'],
                    'available_gb' => $fs['available_gb']
                ];
            } elseif ($fs['health'] === 'warning' || $fs['health'] === 'caution') {
                $summary['warning_volumes'][] = [
                    'mountpoint' => $mountpoint,
                    'use_percent' => $fs['use_percent'],
                    'available_gb' => $fs['available_gb']
                ];
            }

            // Track system vs data volume status
            if ($mountpoint === '/') {
                $summary['system_partition_status'] = $fs['health'];
            } elseif ($mountpoint === '/volume1' || strpos($mountpoint, 'volume') === 1) {
                if ($summary['data_volume_status'] === null || $fs['health'] === 'critical' || $fs['health'] === 'warning') {
                    $summary['data_volume_status'] = $fs['health'];
                }
            }
        }

        // Overall health determination
        if (!empty($summary['critical_volumes'])) {
            $summary['overall_health'] = 'critical';
        } elseif (!empty($summary['warning_volumes'])) {
            $summary['overall_health'] = 'warning';
        } else {
            $summary['overall_health'] = 'healthy';
        }

        return $summary;
    }
}
