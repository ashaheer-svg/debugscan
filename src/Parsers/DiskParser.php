<?php

declare(strict_types=1);

namespace App\Parsers;

class DiskParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $disks = [];
        
        // 1. Load authoritative metadata from load_info.result if exists (Hardwarev2.md Section 2.2.3)
        $loadInfo = $this->getLoadInfo($extractedPath);
        if ($loadInfo && isset($loadInfo['disks'])) {
            foreach ($loadInfo['disks'] as $disk) {
                $id = $disk['id'] ?? null;
                if (!$id) continue;
                
                $disks[$id] = [
                    'model' => $disk['model'] ?? '',
                    'serial' => $disk['serial'] ?? '',
                    'size_gb' => round(($disk['size_total'] ?? 0) / 1024 / 1024 / 1024, 2),
                    'temp' => $disk['temp'] ?? 0,
                    'status' => $disk['status'] ?? '',
                    'smart_status' => $disk['smart_status'] ?? '',
                    'unc' => $disk['unc'] ?? 0,
                    'is_ssd' => $disk['isSsd'] ?? false,
                    'slot' => $disk['slot_id'] ?? null,
                    'container' => $disk['container']['str'] ?? null
                ];
            }
        }

        // 2. Supplement/Fallback with per-disk runtime files (Hardwarev2.md Section 3.2.3 & 9)
        $diskDirs = glob($extractedPath . '/dsm/run/synostorage/disks/*', GLOB_ONLYDIR);
        foreach ($diskDirs as $dir) {
            $diskName = basename($dir);
            if (!isset($disks[$diskName])) {
                $disks[$diskName] = [
                    'status' => 'detected'
                ];
            }
            
            if (file_exists($dir . '/model')) $disks[$diskName]['model'] = trim(file_get_contents($dir . '/model'));
            if (file_exists($dir . '/serial')) $disks[$diskName]['serial'] = trim(file_get_contents($dir . '/serial'));
            if (file_exists($dir . '/temperature')) $disks[$diskName]['temp'] = (int)trim(file_get_contents($dir . '/temperature'));
            
            // Physical Bay Mapping
            if (file_exists($dir . '/id')) $disks[$diskName]['bay'] = (int)trim(file_get_contents($dir . '/id'));
            if (file_exists($dir . '/container')) {
                $container = trim(file_get_contents($dir . '/container'));
                $disks[$diskName]['container'] = empty($container) ? 'Main' : $container;
            } else {
                $disks[$diskName]['container'] = $disks[$diskName]['container'] ?? 'Main';
            }
        }

        // 3. Precise capacity and discovery from /proc/partitions (Hardwarev2.md Section 1.3.1 & 5.1)
        $partitions = $this->parsePartitions($extractedPath);
        foreach ($partitions as $id => $pInfo) {
            if (!isset($disks[$id])) {
                $disks[$id] = $pInfo;
            } else {
                $disks[$id]['size_gb'] = $pInfo['size_gb'];
                $disks[$id]['is_expansion'] = $pInfo['is_expansion'] ?? false;
            }
        }

        return $disks;
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
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $disks = [];
        
        foreach ($lines as $line) {
            // Match whole disks (internal major 8, expansion major 128)
            // Hardwarev2.md Section 1.3.1 & 2.2.1
            if (preg_match('/^\s+(8|128)\s+\d+\s+(\d+)\s+(sd[a-z]+|sata\d+|nvme\d+n\d+)$/', $line, $matches)) {
                $disks[$matches[3]] = [
                    'size_gb' => round((int)$matches[2] / 1024 / 1024, 2),
                    'status' => 'detected',
                    'is_expansion' => ($matches[1] == '128')
                ];
            }
        }
        return $disks;
    }
}
