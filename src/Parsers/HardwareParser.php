<?php

declare(strict_types=1);

namespace App\Parsers;

class HardwareParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $citations = [];
        $major = $context['majorversion'] ?? 7;
        $synoInfoMeta = $this->parseSynoInfo($extractedPath);
        $synoInfo = $synoInfoMeta['data'];
        if (!empty($synoInfoMeta['file'])) {
            $citations[] = [
                'file' => $synoInfoMeta['file'],
                'lines' => '1-' . $synoInfoMeta['lines'],
                'timestamp' => $synoInfoMeta['timestamp']
            ];
        }

        $hardware = [];

        // 1. NAS Serial
        $serialProc = $this->getSerialFromProc($extractedPath);
        if ($serialProc) {
            $hardware['serial'] = $serialProc['value'];
            $citations[] = ['file' => $serialProc['file'], 'lines' => '1', 'timestamp' => $serialProc['timestamp']];
        } else {
            $hardware['serial'] = $synoInfo['serialno'] ?? null;
        }

        // 2. Model extraction
        $modelFile = $extractedPath . '/dsm/proc/sys/kernel/syno_hw_version';
        if (file_exists($modelFile)) {
            $hardware['model'] = trim(file_get_contents($modelFile));
            $citations[] = ['file' => 'dsm/proc/sys/kernel/syno_hw_version', 'lines' => '1', 'timestamp' => date('Y-m-d H:i:s', filemtime($modelFile))];
        } else {
            $unique = $synoInfo['unique'] ?? '';
            if (!empty($unique)) {
                $parts = explode('_', $unique);
                $hardware['model'] = strtoupper(end($parts));
            } else {
                $hardware['model'] = 'Unknown Synology';
            }
        }

        // 3. Location
        $loc = $this->parseLocation($extractedPath);
        if ($loc) {
            $hardware['location'] = $loc['value'];
            $citations[] = ['file' => $loc['file'], 'lines' => '1', 'timestamp' => $loc['timestamp']];
        }

        // 4. CPU Info
        $cpuFile = $extractedPath . '/dsm/proc/cpuinfo';
        if (file_exists($cpuFile)) {
            $cpuContent = file_get_contents($cpuFile);
            $lines = explode("\n", $cpuContent);
            if (preg_match('/model name\s+: (.*)/', $cpuContent, $matches)) {
                $hardware['cpu_model'] = trim($matches[1]);
            }
            $hardware['cpu_cores'] = substr_count($cpuContent, 'processor');
            $citations[] = ['file' => 'dsm/proc/cpuinfo', 'lines' => '1-' . count($lines), 'timestamp' => date('Y-m-d H:i:s', filemtime($cpuFile))];
        }

        // 5. RAM
        $memFile = $extractedPath . '/dsm/proc/meminfo';
        if (file_exists($memFile)) {
            $memContent = file_get_contents($memFile);
            if (preg_match('/MemTotal:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
            if (preg_match('/MemAvailable:\s+(\d+)/', $memContent, $matches)) {
                $hardware['ram_available_gb'] = round((int)$matches[1] / 1024 / 1024, 1);
            }
            $citations[] = ['file' => 'dsm/proc/meminfo', 'lines' => '1-50', 'timestamp' => date('Y-m-d H:i:s', filemtime($memFile))];
        }

        // 6. Uptime
        $upFile = $extractedPath . '/dsm/proc/uptime';
        if (file_exists($upFile)) {
            $uptimeContent = trim(file_get_contents($upFile));
            $parts = explode(' ', $uptimeContent);
            $seconds = (float)$parts[0];
            $hardware['uptime_days'] = round($seconds / 86400, 2);
            $citations[] = ['file' => 'dsm/proc/uptime', 'lines' => '1', 'timestamp' => date('Y-m-d H:i:s', filemtime($upFile))];
        }

        return ['data' => $hardware, 'citations' => $citations];
    }

    /**
     * Extract NAS serial number from /proc/sys/kernel/syno_serial
     * Replaces incorrect logic that looked for non-existent synoinfo.conf keys
     * @return string|null Serial number or null if not found
     */
    private function getSerialFromProc(string $path): ?array
    {
        $serFile = $path . '/dsm/proc/sys/kernel/syno_serial';
        if (file_exists($serFile)) {
            $serial = trim(file_get_contents($serFile));
            return !empty($serial) ? [
                'value' => $serial,
                'file' => 'dsm/proc/sys/kernel/syno_serial',
                'timestamp' => date('Y-m-d H:i:s', filemtime($serFile))
            ] : null;
        }

        // Fallback to custom serial if standard one missing (rare)
        $customFile = $path . '/dsm/proc/sys/kernel/syno_custom_serial';
        if (file_exists($customFile)) {
            $serial = trim(file_get_contents($customFile));
            return !empty($serial) ? [
                'value' => $serial,
                'file' => 'dsm/proc/sys/kernel/syno_custom_serial',
                'timestamp' => date('Y-m-d H:i:s', filemtime($customFile))
            ] : null;
        }

        return null;
    }

    private function parseLocation(string $path): ?array
    {
        $file = $path . '/dsm/etc/snmp/snmpd.conf';
        $relFile = 'dsm/etc/snmp/snmpd.conf';
        if (!file_exists($file)) {
            $file = $path . '/dsm/etc.defaults/snmp/snmpd.conf';
            $relFile = 'dsm/etc.defaults/snmp/snmpd.conf';
        }
        if (!file_exists($file)) return null;

        $content = file_get_contents($file);
        if (preg_match('/^sysLocation\s+"?([^"\n]+)"?/m', $content, $matches)) {
            return [
                'value' => trim($matches[1]),
                'file' => $relFile,
                'timestamp' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }

        return null;
    }

    private function parseSynoInfo(string $path): array
    {
        $file = $path . '/dsm/etc/synoinfo.conf';
        $relFile = 'dsm/etc/synoinfo.conf';
        if (!file_exists($file)) {
            $file = $path . '/dsm/etc.defaults/synoinfo.conf';
            $relFile = 'dsm/etc.defaults/synoinfo.conf';
        }

        if (!file_exists($file)) return ['data' => [], 'file' => '', 'lines' => 0, 'timestamp' => ''];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $data = [];
        foreach ($lines as $line) {
            if (str_contains($line, '=')) {
                list($key, $value) = explode('=', $line, 2);
                $data[trim($key)] = trim($value, '" ');
            }
        }
        return [
            'data' => $data,
            'file' => $relFile,
            'lines' => count($lines),
            'timestamp' => date('Y-m-d H:i:s', filemtime($file))
        ];
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
