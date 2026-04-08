<?php

declare(strict_types=1);

namespace App\Parsers;

class DiskParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $disks = [];
        $smartFiles = glob($extractedPath . '/dsm/run/synostorage/disks/*');

        foreach ($smartFiles as $file) {
            if (is_file($file)) {
                $diskName = basename($file);
                $content = json_decode(file_get_contents($file), true);
                if ($content) {
                    $disks[$diskName] = [
                        'model' => $content['model'] ?? '',
                        'serial' => $content['serial'] ?? '',
                        'size_gb' => round(($content['size'] ?? 0) / 1024 / 1024 / 1024, 2),
                        'temp' => $content['temp'] ?? 0,
                        'status' => $content['status'] ?? '',
                        'smart_status' => $content['smart_status'] ?? '',
                        'bad_sectors' => $content['bad_sector'] ?? 0,
                        'reallocated_sectors' => $content['reallocated_sector'] ?? 0,
                        'power_on_hours' => $content['power_on_hour'] ?? 0,
                    ];
                }
            }
        }

        // If SMART files are missing, fallback to /proc/partitions or disk_log
        if (empty($disks)) {
            $disks = $this->parsePartitions($extractedPath);
        }

        return $disks;
    }

    private function parsePartitions(string $path): array
    {
        $file = $path . '/dsm/proc/partitions';
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $disks = [];
        
        foreach ($lines as $line) {
            if (preg_match('/^\s+\d+\s+\d+\s+(\d+)\s+(sd[a-z]|sata[0-9]|nvme[0-9]n[0-9])$/', $line, $matches)) {
                $disks[$matches[2]] = [
                    'size_gb' => round((int)$matches[1] / 1024 / 1024, 2),
                    'status' => 'detected',
                ];
            }
        }
        return $disks;
    }
}
