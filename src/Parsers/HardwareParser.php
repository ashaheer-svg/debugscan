<?php

declare(strict_types=1);

namespace App\Parsers;

class HardwareParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $major = $context['majorversion'] ?? 7;
        $synoInfo = $this->parseSynoInfo($extractedPath);
        $hardware = [];

        // 1. NAS Serial from /proc/sys/kernel/syno_serial OR synoinfo.conf (Hardwarev2.md Section 2.1.1 & 3.1.1)
        // Primary source: /proc/sys/kernel/syno_serial; Fallback: synoinfo.conf 'serialno'
        $hardware['serial'] = $this->getSerialFromProc($extractedPath) ?: ($synoInfo['serialno'] ?? null);

        // 2. Model extraction (Hardwarev2.md Section 2.1.1 & 3.1.1)
        if (file_exists($extractedPath . '/dsm/proc/sys/kernel/syno_hw_version')) {
            // DSM 7.x: dedicated proc file
            $hardware['model'] = trim(file_get_contents($extractedPath . '/dsm/proc/sys/kernel/syno_hw_version'));
        } else {
            // DSM 6.x: extract from synoinfo.conf "unique" field (format: synology_<cpu>_<model>)
            $unique = $synoInfo['unique'] ?? '';
            if (!empty($unique)) {
                $parts = explode('_', $unique);
                $hardware['model'] = strtoupper(end($parts));
            } else {
                $hardware['model'] = 'Unknown Synology';
            }
        }

        // 3. Extract Location from SNMP (Hardwarev2.md Section 3.7)
        $hardware['location'] = $this->parseLocation($extractedPath);

        // 4. CPU Info (Hardwarev2.md Section 2.1.4 & 3.1.4)
        if (file_exists($extractedPath . '/dsm/proc/cpuinfo')) {
            $cpuContent = file_get_contents($extractedPath . '/dsm/proc/cpuinfo');
            if (preg_match('/model name\s+: (.*)/', $cpuContent, $matches)) {
                $hardware['cpu_model'] = trim($matches[1]);
            }
            $hardware['cpu_cores'] = substr_count($cpuContent, 'processor');
        }

        // 5. RAM (Hardwarev2.md Section 2.1.3 & 3.1.3)
        if (file_exists($extractedPath . '/dsm/proc/meminfo')) {
            $memContent = file_get_contents($extractedPath . '/dsm/proc/meminfo');
            if (preg_match('/MemTotal:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_available_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
        }

        // 6. Uptime
        if (file_exists($extractedPath . '/dsm/proc/uptime')) {
            $uptimeContent = trim(file_get_contents($extractedPath . '/dsm/proc/uptime'));
            $parts = explode(' ', $uptimeContent);
            $seconds = (float)$parts[0];
            $hardware['uptime_days'] = round($seconds / 86400, 2);
        }

        return $hardware;
    }

    /**
     * Extract NAS serial number from /proc/sys/kernel/syno_serial
     * Replaces incorrect logic that looked for non-existent synoinfo.conf keys
     * @return string|null Serial number or null if not found
     */
    private function getSerialFromProc(string $path): ?string
    {
        $serFile = $path . '/dsm/proc/sys/kernel/syno_serial';
        if (file_exists($serFile)) {
            $serial = trim(file_get_contents($serFile));
            return !empty($serial) ? $serial : null;
        }

        // Fallback to custom serial if standard one missing (rare)
        $customFile = $path . '/dsm/proc/sys/kernel/syno_custom_serial';
        if (file_exists($customFile)) {
            $serial = trim(file_get_contents($customFile));
            return !empty($serial) ? $serial : null;
        }

        return null;
    }

    private function parseLocation(string $path): ?string
    {
        $file = $path . '/dsm/etc/snmp/snmpd.conf';
        if (!file_exists($file)) $file = $path . '/dsm/etc.defaults/snmp/snmpd.conf';
        if (!file_exists($file)) return null;

        $content = file_get_contents($file);
        if (preg_match('/^sysLocation\s+"?([^"\n]+)"?/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
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
