<?php

declare(strict_types=1);

namespace App\Parsers;

class DiskParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $disks = [];
        $citations = [];
        
        // 1. Authoritative metadata
        $loadInfoFile = $extractedPath . '/dsm/result/load_info.result';
        if (file_exists($loadInfoFile)) {
            $loadInfo = json_decode(file_get_contents($loadInfoFile), true);
            if ($loadInfo && isset($loadInfo['disks'])) {
                foreach ($loadInfo['disks'] as $disk) {
                    $id = $disk['id'] ?? null;
                    if (!$id) continue;

                    // Determine container: formal metadata or device naming pattern fallback
                    $container = $disk['container']['str'] ?? null;
                    if (!$container && preg_match('/^sde[a-z]/', $id)) {
                        $container = 'Expansion Unit';
                    }

                    // Calculate capacity with fallbacks for different field names
                    $capacity = 0;
                    if (!empty($disk['size_total'])) {
                        $capacity = round((int)$disk['size_total'] / 1024 / 1024 / 1024, 2);
                    } elseif (!empty($disk['size']) && is_numeric($disk['size'])) {
                        $capacity = round((int)$disk['size'] / 1024 / 1024 / 1024, 2);
                    }

                    $disks[$id] = [
                        'model' => $disk['model'] ?? '',
                        'serial' => $disk['serial'] ?? '',
                        'vendor' => $disk['vendor'] ?? '',
                        'firmware' => $disk['firm'] ?? '',
                        'size_gb' => $capacity,
                        'temp' => $disk['temp'] ?? 0,
                        'status' => $disk['status'] ?? '',
                        'smart_status' => $disk['smart_status'] ?? '',
                        'unc' => $disk['unc'] ?? 0,
                        'is_ssd' => $disk['isSsd'] ?? false,
                        'firmware_status' => $disk['firmware_status'] ?? null,
                        'exceed_bad_sector_thr' => $disk['exceed_bad_sector_thr'] ?? false,
                        'slot' => $disk['slot_id'] ?? null,
                        'container' => $container
                    ];
                }
                $citations[] = [
                    'file' => 'dsm/result/load_info.result',
                    'lines' => '1-500',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($loadInfoFile))
                ];
            }
        }

        // 2. Per-disk runtime files
        $diskDirs = glob($extractedPath . '/dsm/run/synostorage/disks/*', GLOB_ONLYDIR);
        foreach ($diskDirs as $dir) {
            $diskName = basename($dir);
            if (!isset($disks[$diskName])) {
                $disks[$diskName] = ['status' => 'detected'];
            }
            
            $relDir = 'dsm/run/synostorage/disks/' . $diskName;
            
            if (file_exists($dir . '/model')) $disks[$diskName]['model'] = trim(file_get_contents($dir . '/model'));
            if (file_exists($dir . '/serial')) $disks[$diskName]['serial'] = trim(file_get_contents($dir . '/serial'));
            if (file_exists($dir . '/temperature')) $disks[$diskName]['temp'] = (int)trim(file_get_contents($dir . '/temperature'));
            
            // Just one general citation for the disk run directory to avoid bloat
            $citations[] = [
                'file' => $relDir,
                'lines' => 'dir',
                'timestamp' => date('Y-m-d H:i:s', filemtime($dir))
            ];

            // Weight-based predictive indicators
            $weightFiles = [
                'reset_fail_weight' => 'reset_fail_weight',
                'timeout_weight' => 'timeout_weight',
                'unc_weight' => 'unc_weight'
            ];
            foreach ($weightFiles as $file => $key) {
                if (file_exists($dir . '/' . $file)) {
                    $value = trim(file_get_contents($dir . '/' . $file));
                    $disks[$diskName][$key] = is_numeric($value) ? (int)$value : $value;
                }
            }
        }

        // 3. Proc/partitions discovery
        $partitionMeta = $this->parsePartitions($extractedPath);
        if (!empty($partitionMeta['data'])) {
            $citations[] = [
                'file' => $partitionMeta['file'],
                'lines' => '1-' . $partitionMeta['lines'],
                'timestamp' => $partitionMeta['timestamp']
            ];
            foreach ($partitionMeta['data'] as $id => $pInfo) {
                if (!isset($disks[$id])) {
                    $disks[$id] = $pInfo;
                } else {
                    $disks[$id]['size_gb'] = $pInfo['size_gb'];
                    $disks[$id]['is_expansion'] = $pInfo['is_expansion'] ?? false;
                }
            }
        }

        return ['data' => $disks, 'citations' => array_unique($citations, SORT_REGULAR)];
    }

    private function getLoadInfo(string $path): ?array
    {
        $file = $path . '/dsm/result/load_info.result';
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }

    private function parsePartitions(string $path): array
    {
        $file = $path . '/dsm/proc/partitions';
        if (!file_exists($file)) return ['data' => [], 'file' => '', 'lines' => 0, 'timestamp' => ''];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $disks = [];
        
        foreach ($lines as $line) {
            // Match whole disks (internal major 8, expansion major 128)
            if (preg_match('/^\s+(8|128)\s+\d+\s+(\d+)\s+(sd[a-z]+|sata\d+|nvme\d+n\d+)$/', $line, $matches)) {
                $disks[$matches[3]] = [
                    'size_gb' => round((int)$matches[2] / 1024 / 1024, 2),
                    'status' => 'detected',
                    'is_expansion' => ($matches[1] == '128')
                ];
            }
        }
        return [
            'data' => $disks,
            'file' => 'dsm/proc/partitions',
            'lines' => count($lines),
            'timestamp' => date('Y-m-d H:i:s', filemtime($file))
        ];
    }
}
