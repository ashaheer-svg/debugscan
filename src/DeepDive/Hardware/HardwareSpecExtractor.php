<?php

declare(strict_types=1);

namespace App\DeepDive\Hardware;

/**
 * Independent hardware specification extractor.
 *
 * Extracts comprehensive hardware specs with DSM 6/7 compatibility.
 * Uses multi-source fallback chains - if primary source unavailable,
 * falls back to alternatives. All extractions include citations.
 *
 * NOT dependent on parsers or other infrastructure.
 * Can be called standalone: new HardwareSpecExtractor()->extract($path)
 */
final class HardwareSpecExtractor
{
    private string $extractedPath = '';

    public function extract(string $extractedPath): HardwareSpec
    {
        $this->extractedPath = rtrim($extractedPath, '/\\');
        $spec = new HardwareSpec();

        // Device Identity
        $spec->model = $this->extractModel();
        $spec->serial = $this->extractSerial();
        $spec->location = $this->extractLocation();

        // CPU & RAM
        $cpu = $this->extractCpuInfo();
        if ($cpu) {
            $spec->cpu = $cpu['data'];
            $this->addCitation($spec, 'cpu', $cpu['file'], $cpu['timestamp']);
        }

        $ram = $this->extractRamInfo();
        if ($ram) {
            $spec->ram = $ram['data'];
            $this->addCitation($spec, 'ram', $ram['file'], $ram['timestamp']);
        }

        // Uptime
        $uptime = $this->extractUptime();
        if ($uptime !== null) {
            $spec->uptime_days = $uptime['days'];
            $this->addCitation($spec, 'uptime', $uptime['file'], $uptime['timestamp']);
        }

        // Drive Bays
        $bays = $this->extractDriveBays();
        if ($bays) {
            $spec->driveBays = $bays['data'];
            $this->addCitation($spec, 'drive_bays', $bays['file'], $bays['timestamp']);
        }

        // Drives
        $drives = $this->extractDrives();
        if ($drives) {
            $spec->drives = $drives['data'];
            $this->addCitation($spec, 'drives', $drives['file'], $drives['timestamp']);
        }

        // RAID Configuration
        $raid = $this->extractRaidConfig();
        if ($raid) {
            $spec->raidConfig = $raid['data'];
            $this->addCitation($spec, 'raid_config', $raid['file'], $raid['timestamp']);
        }

        // Volumes
        $volumes = $this->extractVolumes();
        if ($volumes) {
            $spec->volumes = $volumes['data'];
            $this->addCitation($spec, 'volumes', $volumes['file'], $volumes['timestamp']);
        }

        // Expansion
        $expansion = $this->extractExpansion();
        if ($expansion) {
            $spec->expansion = $expansion['data'];
            $this->addCitation($spec, 'expansion', $expansion['file'], $expansion['timestamp']);
        }

        return $spec;
    }

    /**
     * Extract NAS model name
     */
    private function extractModel(): string
    {
        // Try 1: /proc/sys/kernel/syno_hw_version (most reliable)
        $file = $this->extractedPath . '/dsm/proc/sys/kernel/syno_hw_version';
        if (file_exists($file)) {
            $model = trim((string)@file_get_contents($file));
            if (!empty($model)) {
                return $model;
            }
        }

        // Try 2: synoinfo.conf "unique" field parsing
        $synoinfo = $this->parseSynoinfo();
        if (!empty($synoinfo['unique'])) {
            $parts = explode('_', $synoinfo['unique']);
            return strtoupper((string)end($parts));
        }

        // Try 3: Load info
        $loadInfo = $this->parseJsonResult('load_info.result');
        if (is_array($loadInfo) && !empty($loadInfo['model_name'])) {
            return $loadInfo['model_name'];
        }

        return '';
    }

    /**
     * Extract NAS serial number
     */
    private function extractSerial(): string
    {
        // Try 1: /proc/sys/kernel/syno_serial
        $file = $this->extractedPath . '/dsm/proc/sys/kernel/syno_serial';
        if (file_exists($file)) {
            $serial = trim((string)@file_get_contents($file));
            if (!empty($serial)) {
                return $serial;
            }
        }

        // Try 2: /proc/sys/kernel/syno_custom_serial (fallback)
        $file = $this->extractedPath . '/dsm/proc/sys/kernel/syno_custom_serial';
        if (file_exists($file)) {
            $serial = trim((string)@file_get_contents($file));
            if (!empty($serial)) {
                return $serial;
            }
        }

        // Try 3: synoinfo.conf serialno field
        $synoinfo = $this->parseSynoinfo();
        if (!empty($synoinfo['serialno'])) {
            return $synoinfo['serialno'];
        }

        return '';
    }

    /**
     * Extract device location (from SNMP config)
     */
    private function extractLocation(): string
    {
        $locations = [
            $this->extractedPath . '/dsm/etc/snmp/snmpd.conf',
            $this->extractedPath . '/dsm/etc.defaults/snmp/snmpd.conf',
        ];

        foreach ($locations as $file) {
            if (file_exists($file)) {
                $content = (string)@file_get_contents($file);
                if (preg_match('/^sysLocation\s+"?([^"\n]+)"?/m', $content, $m)) {
                    return trim($m[1]);
                }
            }
        }

        return '';
    }

    /**
     * Extract CPU information
     */
    private function extractCpuInfo(): ?array
    {
        $file = $this->extractedPath . '/dsm/proc/cpuinfo';
        if (!file_exists($file)) {
            return null;
        }

        $content = (string)@file_get_contents($file);
        $data = [
            'model' => '',
            'cores' => 0,
            'threads' => 0,
        ];

        // Extract model name
        if (preg_match('/model name\s*:\s*(.+?)$/m', $content, $m)) {
            $data['model'] = trim($m[1]);
        }

        // Count processors
        $cores = substr_count($content, 'processor');
        $data['cores'] = $cores;
        $data['threads'] = $cores;

        return [
            'data' => $data,
            'file' => 'dsm/proc/cpuinfo',
            'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    /**
     * Extract RAM information
     */
    private function extractRamInfo(): ?array
    {
        $file = $this->extractedPath . '/dsm/proc/meminfo';
        if (!file_exists($file)) {
            return null;
        }

        $content = (string)@file_get_contents($file);
        $data = [
            'total_gb' => 0,
            'available_gb' => 0,
        ];

        // Parse MemTotal
        if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $content, $m)) {
            $data['total_gb'] = round((int)$m[1] / 1024 / 1024, 2);
        }

        // Parse MemAvailable
        if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $content, $m)) {
            $data['available_gb'] = round((int)$m[1] / 1024 / 1024, 2);
        }

        return [
            'data' => $data,
            'file' => 'dsm/proc/meminfo',
            'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    /**
     * Extract system uptime
     */
    private function extractUptime(): ?array
    {
        $file = $this->extractedPath . '/dsm/proc/uptime';
        if (!file_exists($file)) {
            return null;
        }

        $content = trim((string)@file_get_contents($file));
        $parts = explode(' ', $content);
        $seconds = (float)($parts[0] ?? 0);

        return [
            'days' => round($seconds / 86400, 2),
            'file' => 'dsm/proc/uptime',
            'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
        ];
    }

    /**
     * Extract drive bay count
     */
    private function extractDriveBays(): ?array
    {
        // Try 1: load_info.result
        $loadInfo = $this->parseJsonResult('load_info.result');
        if (is_array($loadInfo) && isset($loadInfo['max_bay_count'])) {
            return [
                'data' => [
                    'total' => (int)$loadInfo['max_bay_count'],
                    'used' => isset($loadInfo['drives']) ? count((array)$loadInfo['drives']) : 0,
                    'expansion_count' => 0,
                ],
                'file' => 'dsm/result/load_info.result',
                'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result')),
            ];
        }

        // Try 2: synoinfo.conf parsing (count bay-related entries)
        $synoinfo = $this->parseSynoinfo();
        $bayCount = 0;
        foreach ($synoinfo as $key => $value) {
            if (preg_match('/^(external_)?slot\d+_type/i', $key)) {
                $bayCount++;
            }
        }
        if ($bayCount > 0) {
            return [
                'data' => [
                    'total' => $bayCount,
                    'used' => 0,
                    'expansion_count' => 0,
                ],
                'file' => 'dsm/etc/synoinfo.conf',
                'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/etc/synoinfo.conf')),
            ];
        }

        return null;
    }

    /**
     * Extract individual drive information
     */
    private function extractDrives(): ?array
    {
        $drives = [];
        $sourceFile = '';
        $timestamp = '';

        // Try 1: load_info.result (DSM 6/7)
        $loadInfo = $this->parseJsonResult('load_info.result');
        if (is_array($loadInfo) && isset($loadInfo['drives']) && is_array($loadInfo['drives'])) {
            $bay = 1;
            foreach ($loadInfo['drives'] as $drive) {
                if (is_array($drive)) {
                    $drives[] = [
                        'bay' => $bay,
                        'device' => $drive['device'] ?? '',
                        'model' => $drive['model'] ?? $drive['product'] ?? '',
                        'serial' => $drive['serial'] ?? '',
                        'capacity_gb' => isset($drive['capacity']) ? round((int)$drive['capacity'] / (1000**3), 1) : 0,
                        'firmware' => $drive['firmware'] ?? '',
                        'temperature_celsius' => $drive['temperature'] ?? 0,
                        'smart_status' => $drive['smart_status'] ?? 'unknown',
                        'power_on_hours' => $drive['power_on_hours'] ?? 0,
                    ];
                    $bay++;
                }
            }
            $sourceFile = 'dsm/result/load_info.result';
            $timestamp = date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result'));
        }

        // Try 2: Fallback to diskstats
        if (empty($drives)) {
            $diskstats = $this->extractedPath . '/dsm/proc/diskstats';
            if (file_exists($diskstats)) {
                $content = (string)@file_get_contents($diskstats);
                $bay = 1;
                foreach (explode("\n", $content) as $line) {
                    if (preg_match('/\s+sd[a-z]\s+/', $line, $m)) {
                        $drives[] = [
                            'bay' => $bay,
                            'device' => trim($m[0]),
                            'model' => 'Unknown',
                            'serial' => '',
                            'capacity_gb' => 0,
                            'firmware' => '',
                            'temperature_celsius' => 0,
                            'smart_status' => 'unknown',
                            'power_on_hours' => 0,
                        ];
                        $bay++;
                    }
                }
                $sourceFile = 'dsm/proc/diskstats';
                $timestamp = date('Y-m-d H:i:s', filemtime($diskstats));
            }
        }

        if (empty($drives)) {
            return null;
        }

        return [
            'data' => $drives,
            'file' => $sourceFile,
            'timestamp' => $timestamp,
        ];
    }

    /**
     * Extract RAID array configuration
     */
    private function extractRaidConfig(): ?array
    {
        $arrays = [];
        $degradedCount = 0;
        $rebuildingCount = 0;

        $mdstat = $this->extractedPath . '/dsm/proc/mdstat';
        if (!file_exists($mdstat)) {
            return null;
        }

        $content = (string)@file_get_contents($mdstat);
        $lines = explode("\n", $content);

        $current = null;
        foreach ($lines as $line) {
            // Header: "md0 : active raid5 sda3[0] sdb3[1] sdc3[2]"
            if (preg_match('/^(md\d+)\s*:\s*(\S+)\s+(\S+)\s+(.*)$/', $line, $m)) {
                if ($current !== null) {
                    $arrays[] = $current;
                }

                $current = [
                    'name' => $m[1],
                    'state' => $m[2],
                    'level' => $m[3],
                    'members' => count(array_filter(explode(' ', $m[4]))),
                    'healthy_members' => count(array_filter(explode(' ', $m[4]))),
                    'missing_members' => 0,
                    'devices' => array_filter(preg_split('/[\s\[\]]/', $m[4])),
                    'rebuild_progress' => null,
                    'sync_action' => 'idle',
                ];

                // Track degraded/rebuilding
                if ($m[2] === 'degraded') {
                    $degradedCount++;
                }
            } elseif ($current !== null && preg_match('/\[([_U]+)\]/', $line, $m)) {
                // Status: "[UU_]" - underscore = missing
                $missing = substr_count($m[1], '_');
                $current['missing_members'] = $missing;
                $current['healthy_members'] = $current['members'] - $missing;
                if ($missing > 0) {
                    $degradedCount++;
                }
            } elseif ($current !== null && preg_match('/(recovery|resync|reshape)\s*=\s*([\d.]+)%/i', $line, $m)) {
                // Rebuild progress
                $current['rebuild_progress'] = (float)$m[2];
                $current['sync_action'] = strtolower($m[1]);
                $rebuildingCount++;
            }
        }

        if ($current !== null) {
            $arrays[] = $current;
        }

        return [
            'data' => [
                'arrays' => $arrays,
                'total_arrays' => count($arrays),
                'degraded_arrays' => $degradedCount,
                'rebuilding_arrays' => $rebuildingCount,
            ],
            'file' => 'dsm/proc/mdstat',
            'timestamp' => date('Y-m-d H:i:s', filemtime($mdstat)),
        ];
    }

    /**
     * Extract volume configuration
     */
    private function extractVolumes(): ?array
    {
        $volumes = [];

        // Try 1: df.result
        $df = $this->extractedPath . '/dsm/result/df.result';
        if (file_exists($df)) {
            $content = (string)@file_get_contents($df);
            $lines = explode("\n", $content);

            foreach ($lines as $line) {
                if (preg_match('/^\S+\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)%\s+(\S.*)$/', $line, $m)) {
                    $total = (int)$m[1];
                    $used = (int)$m[2];
                    $available = (int)$m[3];
                    $percent = (int)$m[4];
                    $mount = trim($m[5]);

                    $volumes[] = [
                        'name' => basename($mount) ?: $mount,
                        'mount_point' => $mount,
                        'filesystem' => 'unknown',
                        'total_gb' => round($total / 1024 / 1024, 1),
                        'used_gb' => round($used / 1024 / 1024, 1),
                        'available_gb' => round($available / 1024 / 1024, 1),
                        'usage_percent' => $percent,
                    ];
                }
            }

            if (!empty($volumes)) {
                $totalGb = array_sum(array_column($volumes, 'total_gb'));
                $usedGb = array_sum(array_column($volumes, 'used_gb'));

                return [
                    'data' => [
                        'volumes' => $volumes,
                        'total_volumes' => count($volumes),
                        'total_capacity_gb' => round($totalGb, 1),
                        'total_used_gb' => round($usedGb, 1),
                    ],
                    'file' => 'dsm/result/df.result',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($df)),
                ];
            }
        }

        return null;
    }

    /**
     * Extract expansion unit information
     */
    private function extractExpansion(): ?array
    {
        $synoinfo = $this->parseSynoinfo();

        // Check for expansion_slot_type or related fields
        $hasExpansion = false;
        $expansionType = '';

        foreach ($synoinfo as $key => $value) {
            if (preg_match('/expansion.*type/i', $key) && !empty($value)) {
                $hasExpansion = true;
                $expansionType = $value;
                break;
            }
        }

        if (!$hasExpansion) {
            return null;
        }

        return [
            'data' => [
                'has_expansion' => true,
                'expansion_type' => $expansionType,
                'expansion_bays' => 5, // Most common for DX517, etc
                'expansion_drives' => 0, // Would need SYNODISKDB to know
            ],
            'file' => 'dsm/etc/synoinfo.conf',
            'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/etc/synoinfo.conf')),
        ];
    }

    /**
     * Parse synoinfo.conf into key-value array
     * @return array<string, string>
     */
    private function parseSynoinfo(): array
    {
        $locations = [
            $this->extractedPath . '/dsm/etc/synoinfo.conf',
            $this->extractedPath . '/dsm/etc.defaults/synoinfo.conf',
        ];

        foreach ($locations as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $content = (string)@file_get_contents($file);
            $data = [];

            foreach (explode("\n", $content) as $line) {
                if (strpos($line, '=') === false) {
                    continue;
                }

                [$key, $value] = explode('=', $line, 2);
                $data[trim($key)] = trim($value, '" ');
            }

            return $data;
        }

        return [];
    }

    /**
     * Parse JSON .result file
     */
    private function parseJsonResult(string $filename): mixed
    {
        $file = $this->extractedPath . '/dsm/result/' . $filename;
        if (!file_exists($file)) {
            return null;
        }

        $content = (string)@file_get_contents($file);
        return json_decode($content, associative: true);
    }

    /**
     * Add citation for extracted field
     */
    private function addCitation(HardwareSpec $spec, string $field, string $file, string $timestamp): void
    {
        $spec->citations[$field] = [
            'file' => $file,
            'timestamp' => $timestamp,
        ];
    }
}
