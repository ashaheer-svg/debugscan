<?php

declare(strict_types=1);

namespace App\Parsers;

class HardwareParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $major = $context['majorversion'] ?? 7;
        $hardware = [];

        // 1. Model Name (Hardwarev2.md Section 3.1.1 & 2.1.1)
        if (file_exists($extractedPath . '/dsm/proc/sys/kernel/syno_hw_version')) {
            $hardware['model'] = trim(file_get_contents($extractedPath . '/dsm/proc/sys/kernel/syno_hw_version'));
        } else {
            // For DSM 6.x or older, fallback to synoinfo.conf "unique" field
            $synoInfo = $this->parseSynoInfo($extractedPath);
            $unique = $synoInfo['unique'] ?? '';
            // e.g. "synology_avoton_rs818+" -> "RS818+"
            if (!empty($unique)) {
                $parts = explode('_', $unique);
                $hardware['model'] = strtoupper(end($parts));
            } else {
                $hardware['model'] = 'Unknown Synology';
            }
        }

        // 2. Serial Number - Usually from load_info.result if available
        $loadInfo = $this->getLoadInfo($extractedPath);
        if ($loadInfo && isset($loadInfo['serial'])) {
            $hardware['serial'] = $loadInfo['serial'];
        }

        // 3. CPU Info
        if (file_exists($extractedPath . '/dsm/proc/cpuinfo')) {
            $cpuContent = file_get_contents($extractedPath . '/dsm/proc/cpuinfo');
            if (preg_match('/model name\s+: (.*)/', $cpuContent, $matches)) {
                $hardware['cpu_model'] = trim($matches[1]);
            }
            $hardware['cpu_cores'] = substr_count($cpuContent, 'processor');
        }

        // 4. RAM (Hardwarev2.md Section 3.1.3)
        if (file_exists($extractedPath . '/dsm/proc/meminfo')) {
            $memContent = file_get_contents($extractedPath . '/dsm/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_available_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
        }

        // 5. Uptime
        if (file_exists($extractedPath . '/dsm/proc/uptime')) {
            $uptimeContent = trim(file_get_contents($extractedPath . '/dsm/proc/uptime'));
            $parts = explode(' ', $uptimeContent);
            $seconds = (float)$parts[0];
            $hardware['uptime_days'] = round($seconds / 86400, 2);
        }

        return $hardware;
    }

    private function parseSynoInfo(string $path): array
    {
        $file = $path . '/dsm/etc/synoinfo.conf';
        if (!file_exists($file)) $file = $path . '/dsm/etc.defaults/synoinfo.conf';
        
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $data = [];
        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                list($key, $value) = explode('=', $line, 2);
                $data[trim($key)] = trim($value, '" ');
            }
        }
        return $data;
    }

    private function getLoadInfo(string $path): ?array
    {
        $file = $path . '/dsm/result/load_info.result';
        if (file_exists($file)) {
            return json_decode(file_get_contents($file), true);
        }
        return null;
    }
}
