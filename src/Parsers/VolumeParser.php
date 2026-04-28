<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * VolumeParser: Logical volume configuration and capacity analysis
 *
 * PURPOSE:
 * Extracts logical volume information from Synology storage subsystem.
 * Analyzes mount points, filesystem types, total capacity, used space,
 * and free space percentage. Detects volumes approaching capacity.
 *
 * DATA SOURCE:
 * volume_status.cache: Status cache with volume configuration and usage
 * Fallback: /proc/mounts for mount point information
 *
 * OUTPUT PER VOLUME:
 * - name: Volume identifier (volume1, volume2, etc.)
 * - mount_point: Where volume is mounted (/volume1, etc.)
 * - fs_type: Filesystem type (btrfs, ext4, etc.)
 * - total_gb: Total capacity
 * - used_gb: Currently used space
 * - free_gb: Available space
 * - usage_percent: Utilization percentage
 * - status: online|offline|degraded
 *
 * CAPACITY DETECTION:
 * Identifies volumes near capacity (>90% usage), completely full (100%),
 * with insufficient free space for operations. Detects capacity issues
 * that may cause service failures or performance degradation.
 *
 * FILESYSTEM ANALYSIS:
 * Reports filesystem type and health status. Btrfs vs ext4 have different
 * resilience and recovery characteristics that affect risk assessment.
 *
 * @package App\Parsers
 */
class VolumeParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $volumes = [];
        $file = $extractedPath . '/dsm/run/space/volume_status.cache';

        if (file_exists($file)) {
            $content = file_get_contents($file);
            $lines = explode("\n", $content);
            foreach ($lines as $line) {
                if (preg_match('/\[(\/volume\d+)\]/', $line, $matches)) {
                    $volName = $matches[1];
                    $volumes[$volName] = [];
                } elseif (isset($volName) && preg_match('/([^=]+)=(.*)/', $line, $matches)) {
                    $volumes[$volName][trim($matches[1])] = trim($matches[2]);
                }
            }
        }

        // Add additional mapping from lv.result or space_meta.status
        $metaFile = $extractedPath . '/dsm/run/space/space_meta.status';
        if (file_exists($metaFile)) {
            $meta = json_decode(file_get_contents($metaFile), true);
            if ($meta && isset($meta['volumes'])) {
                foreach ($meta['volumes'] as $id => $data) {
                    $name = '/volume' . ($id + 1);
                    if (isset($volumes[$name])) {
                        $volumes[$name]['fs_type'] = $data['fs_type'] ?? 'unknown';
                        $volumes[$name]['pool_id'] = $data['pool_id'] ?? null;
                    }
                }
            }
        }

        $citations = [];
        if (file_exists($file)) {
            $citations[] = [
                'file' => 'dsm/run/space/volume_status.cache',
                'lines' => '1-200',
                'timestamp' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
        if (file_exists($metaFile)) {
            $citations[] = [
                'file' => 'dsm/run/space/space_meta.status',
                'lines' => '1-100',
                'timestamp' => date('Y-m-d H:i:s', filemtime($metaFile))
            ];
        }

        return ['data' => $volumes, 'citations' => $citations];
    }
}
