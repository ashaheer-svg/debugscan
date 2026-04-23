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
     * Extract drive bay count including expansion units
     */
    private function extractDriveBays(): ?array
    {
        // Try 1: load_info.result - most comprehensive
        $loadInfo = $this->parseJsonResult('load_info.result');
        if (is_array($loadInfo)) {
            // Handle both DSM 6 and DSM 7 JSON structures
            $disksArray = $loadInfo['data']['disks'] ?? $loadInfo['disks'] ?? [];

            $mainBayCount = $loadInfo['max_bay_count'] ?? 0;
            $diskCount = !empty($disksArray) ? count($disksArray) : 0;
            $expansionBayCount = 0;
            $expansionDiskCount = 0;

            // Count expansion disks if present
            if (!empty($disksArray)) {
                foreach ($disksArray as $disk) {
                    if (is_array($disk) && !empty($disk['container']['str'])) {
                        if (preg_match('/expansion/i', $disk['container']['str'])) {
                            $expansionDiskCount++;
                        }
                    }
                }
            }

            // Extract expansion bay count from enclosures
            if (!empty($loadInfo['enclosures'])) {
                foreach ((array)$loadInfo['enclosures'] as $enclosure) {
                    if (is_array($enclosure) && !empty($enclosure['id'])) {
                        if (preg_match('/expansion/i', (string)$enclosure['id'])) {
                            $expansionBayCount += $enclosure['bay_count'] ?? 5;
                        }
                    }
                }
            }

            $mainDiskCount = $diskCount - $expansionDiskCount;
            if ($mainBayCount > 0 || $diskCount > 0) {
                return [
                    'data' => [
                        'main_unit_bays' => (int)($mainBayCount ?: max(4, $mainDiskCount + 1)),
                        'main_unit_used' => max(0, $mainDiskCount),
                        'expansion_unit_bays' => $expansionBayCount,
                        'expansion_unit_used' => $expansionDiskCount,
                        'total_bays' => (int)($mainBayCount ?: max(4, $mainDiskCount + 1)) + $expansionBayCount,
                        'total_used' => $diskCount,
                    ],
                    'file' => 'dsm/result/load_info.result',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result')),
                ];
            }
        }

        // Try 2: Count actual disks in synostorage directory
        $diskDirs = glob($this->extractedPath . '/dsm/run/synostorage/disks/*', GLOB_ONLYDIR);
        if (!empty($diskDirs)) {
            $diskCount = count($diskDirs);
            return [
                'data' => [
                    'main_unit_bays' => max(4, $diskCount),
                    'main_unit_used' => $diskCount,
                    'expansion_unit_bays' => 0,
                    'expansion_unit_used' => 0,
                    'total_bays' => max(4, $diskCount),
                    'total_used' => $diskCount,
                ],
                'file' => 'dsm/run/synostorage/disks/',
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }

        // Try 3: synoinfo.conf parsing for bay capacity
        $synoinfo = $this->parseSynoinfo();
        $mainBayCount = 0;
        $expansionBayCount = 0;

        foreach ($synoinfo as $key => $value) {
            // Main unit bays: slot0_type, slot1_type, etc.
            if (preg_match('/^slot\d+_type$/i', $key) && !empty($value)) {
                $mainBayCount++;
            }
            // Expansion bays: external_slot0_type, expansion_bays, etc.
            if (preg_match('/^(external_)?slot\d+_type$/i', $key) && preg_match('/^external/i', $key) && !empty($value)) {
                $expansionBayCount++;
            }
            // Explicit expansion bay count
            if (preg_match('/^expansion_bays?$/i', $key)) {
                $expansionBayCount = (int)$value;
            }
        }

        if ($mainBayCount > 0 || $expansionBayCount > 0) {
            return [
                'data' => [
                    'main_unit_bays' => max($mainBayCount, 4),
                    'main_unit_used' => 0,
                    'expansion_unit_bays' => $expansionBayCount,
                    'expansion_unit_used' => 0,
                    'total_bays' => max($mainBayCount, 4) + $expansionBayCount,
                    'total_used' => 0,
                ],
                'file' => 'dsm/etc/synoinfo.conf',
                'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/etc/synoinfo.conf')),
            ];
        }

        return null;
    }

    /**
     * Extract individual drive information with container/expansion mapping
     */
    private function extractDrives(): ?array
    {
        $drives = [];
        $sourceFile = '';
        $timestamp = '';
        $mainUnitBays = 0;  // Track how many bays in main unit

        // Try 1: load_info.result (DSM 6/7) - uses 'disks' array with container info
        $loadInfo = $this->parseJsonResult('load_info.result');
        // Handle both DSM 6 and DSM 7 JSON structures
        $disksArray = $loadInfo['data']['disks'] ?? $loadInfo['disks'] ?? [];
        if (is_array($loadInfo) && !empty($disksArray)) {
            $mainBay = 1;
            $expansionBays = [];  // Track expansion unit bays separately
            $devicePattern = [];    // Map device to bay for expansion detection

            foreach ($disksArray as $disk) {
                if (!is_array($disk)) continue;

                $deviceId = $disk['id'] ?? '';
                $devicePattern[$deviceId] = $disk;

                // Determine which unit this drive belongs to
                $container = $disk['container']['str'] ?? null;
                $isExpansion = false;
                $location = 'main';

                // Check if this is an expansion unit drive
                if (!empty($container)) {
                    if (preg_match('/expansion/i', $container) || preg_match('/^[a-z0-9]+-expansion/i', $container)) {
                        $isExpansion = true;
                        $location = $container;
                        if (!isset($expansionBays[$container])) {
                            $expansionBays[$container] = 1;
                        }
                        $bay = $expansionBays[$container];
                        $expansionBays[$container]++;
                    }
                }

                // Fallback: detect expansion based on device naming pattern
                // Synology uses sdea, sdeb, sdec, etc. for expansion units
                if (!$isExpansion && preg_match('/^sde[a-z]/', $deviceId)) {
                    $isExpansion = true;
                    $location = 'expansion_unit_1';
                    if (!isset($expansionBays['expansion_unit_1'])) {
                        $expansionBays['expansion_unit_1'] = 1;
                    }
                    $bay = $expansionBays['expansion_unit_1'];
                    $expansionBays['expansion_unit_1']++;
                }

                if (!$isExpansion) {
                    $bay = $mainBay;
                    $mainBay++;
                }

                // Calculate capacity from multiple sources
                $capacity = 0;
                if (!empty($disk['size_total'])) {
                    $capacity = round((int)$disk['size_total'] / (1000**3), 1);
                } elseif (!empty($disk['size']) && is_numeric($disk['size'])) {
                    // Try alternate field name
                    $capacity = round((int)$disk['size'] / (1000**3), 1);
                }

                $drives[] = [
                    'bay' => $bay,
                    'location' => $location,
                    'device' => $deviceId,
                    'model' => $disk['model'] ?? '',
                    'serial' => $disk['serial'] ?? '',
                    'vendor' => $disk['vendor'] ?? '',
                    'capacity_gb' => $capacity,
                    'firmware' => $disk['firm'] ?? '',
                    'temperature_celsius' => $disk['temp'] ?? 0,
                    'smart_status' => $disk['smart_status'] ?? 'unknown',
                    'power_on_hours' => (int)($disk['power_on_hours'] ?? 0),
                    'is_ssd' => $disk['isSsd'] ?? false,
                    'status' => $disk['status'] ?? 'unknown',
                ];
            }
            $mainUnitBays = $mainBay - 1;
            $sourceFile = 'dsm/result/load_info.result';
            $timestamp = date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result'));
        }

        // Try 2: Fallback to /dsm/run/synostorage/disks directory structure
        if (empty($drives)) {
            $diskDirs = glob($this->extractedPath . '/dsm/run/synostorage/disks/*', GLOB_ONLYDIR);
            if (!empty($diskDirs)) {
                $bay = 1;
                sort($diskDirs);
                foreach ($diskDirs as $dir) {
                    $diskName = basename($dir);
                    $drive = [
                        'bay' => $bay,
                        'location' => 'main',
                        'device' => $diskName,
                        'model' => '',
                        'serial' => '',
                        'vendor' => '',
                        'capacity_gb' => 0,
                        'firmware' => '',
                        'temperature_celsius' => 0,
                        'smart_status' => 'unknown',
                        'power_on_hours' => 0,
                        'is_ssd' => false,
                        'status' => 'detected',
                    ];

                    // Read available metadata from disk directory
                    if (file_exists($dir . '/model')) {
                        $drive['model'] = trim((string)@file_get_contents($dir . '/model'));
                    }
                    if (file_exists($dir . '/serial')) {
                        $drive['serial'] = trim((string)@file_get_contents($dir . '/serial'));
                    }
                    if (file_exists($dir . '/temperature')) {
                        $drive['temperature_celsius'] = (int)trim((string)@file_get_contents($dir . '/temperature'));
                    }

                    $drives[] = $drive;
                    $bay++;
                }
                $mainUnitBays = count($drives);
                $sourceFile = 'dsm/run/synostorage/disks/';
                $timestamp = date('Y-m-d H:i:s');
            }
        }

        // Try 3: Fallback to /dsm/proc/partitions discovery with scsi device type detection
        if (empty($drives)) {
            $partitions = $this->extractedPath . '/dsm/proc/partitions';
            if (file_exists($partitions)) {
                $content = (string)@file_get_contents($partitions);
                $bay = 1;
                foreach (explode("\n", $content) as $line) {
                    // Match whole disks (major 8 = internal, major 128 = expansion)
                    if (preg_match('/^\s+(8|128)\s+\d+\s+(\d+)\s+(sd[a-z]+|sata\d+|nvme\d+n\d+)$/', $line, $m)) {
                        $isExpansion = ($m[1] == '128');
                        $location = $isExpansion ? 'expansion' : 'main';

                        if ($isExpansion && $mainUnitBays === 0) {
                            // If we only have partitions data, assume first device is main unit start
                            $mainUnitBays = 1;
                        }

                        $drives[] = [
                            'bay' => $bay,
                            'location' => $location,
                            'device' => $m[3],
                            'model' => 'Unknown',
                            'serial' => '',
                            'vendor' => '',
                            'capacity_gb' => round((int)$m[2] / 1024 / 1024, 2),
                            'firmware' => '',
                            'temperature_celsius' => 0,
                            'smart_status' => 'unknown',
                            'power_on_hours' => 0,
                            'is_ssd' => false,
                            'status' => 'detected',
                        ];
                        $bay++;
                    }
                }
                $sourceFile = 'dsm/proc/partitions';
                $timestamp = date('Y-m-d H:i:s', filemtime($partitions));
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

                $mdName = $m[1];
                $current = [
                    'name' => $mdName,
                    'state' => $m[2],
                    'level' => $m[3],
                    'type' => $this->classifyRaidArray($mdName),  // internal vs user
                    'members' => count(array_filter(explode(' ', $m[4]))),
                    'healthy_members' => count(array_filter(explode(' ', $m[4]))),
                    'missing_members' => 0,
                    'devices' => array_filter(preg_split('/[\s\[\]]/', $m[4])),
                    'rebuild_progress' => null,
                    'sync_action' => 'idle',
                ];

                // Track degraded/rebuilding - but not for internal system arrays
                // md0 and md1 are designed to handle missing drives and function on single drive
                if ($m[2] === 'degraded' && $current['type'] !== 'internal') {
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
     * Extract expansion unit information - supports multiple units
     */
    private function extractExpansion(): ?array
    {
        $units = [];
        $sourceFile = '';
        $timestamp = '';

        // Try 1: load_info.result - check for enclosure/container info + device patterns
        $loadInfo = $this->parseJsonResult('load_info.result');
        // Handle both DSM 6 and DSM 7 JSON structures
        $disksArray = $loadInfo['data']['disks'] ?? $loadInfo['disks'] ?? [];
        if (is_array($loadInfo) && !empty($disksArray)) {
            $expansionContainers = [];
            $expansionDevices = [];  // Track devices with expansion naming pattern

            // Scan disks for expansion container references and device patterns
            foreach ($disksArray as $disk) {
                if (!is_array($disk)) continue;

                $deviceId = $disk['id'] ?? '';

                // Method 1: Check container metadata
                if (!empty($disk['container']['str'])) {
                    $container = $disk['container']['str'];
                    if (preg_match('/expansion/i', $container)) {
                        if (!isset($expansionContainers[$container])) {
                            $expansionContainers[$container] = [
                                'name' => $container,
                                'bay_count' => 0,
                                'drives' => [],
                            ];
                        }
                        $expansionContainers[$container]['bay_count']++;
                        $expansionContainers[$container]['drives'][] = $deviceId;
                    }
                }

                // Method 2: Detect expansion by device naming (sdea, sdeb, sdec, etc.)
                if (preg_match('/^sde[a-z]/', $deviceId)) {
                    if (!isset($expansionDevices['expansion_unit_1'])) {
                        $expansionDevices['expansion_unit_1'] = [
                            'drives' => [],
                            'bay_count' => 0,
                        ];
                    }
                    $expansionDevices['expansion_unit_1']['drives'][] = $deviceId;
                    $expansionDevices['expansion_unit_1']['bay_count']++;
                }
            }

            // Parse expansion info from load_info enclosures
            if (!empty($loadInfo['enclosures'])) {
                foreach ((array)$loadInfo['enclosures'] as $enclosure) {
                    if (!is_array($enclosure)) continue;

                    $enclosureId = $enclosure['id'] ?? null;
                    if ($enclosureId && preg_match('/expansion/i', (string)$enclosureId)) {
                        $units[] = [
                            'enclosure_id' => $enclosureId,
                            'model' => $enclosure['model'] ?? '',
                            'serial' => $enclosure['serial'] ?? '',
                            'firmware' => $enclosure['firmware'] ?? '',
                            'bay_count' => $enclosure['bay_count'] ?? 5,
                            'installed_drives' => count($expansionContainers[$enclosureId]['drives'] ?? $expansionDevices[$enclosureId]['drives'] ?? []),
                            'drives' => $expansionContainers[$enclosureId]['drives'] ?? $expansionDevices[$enclosureId]['drives'] ?? [],
                            'status' => $enclosure['status'] ?? 'unknown',
                            'power_status' => $enclosure['power_status'] ?? 'unknown',
                        ];
                    }
                }
            }

            // If we found expansion containers but not formal enclosure data
            if (empty($units) && !empty($expansionContainers)) {
                foreach ($expansionContainers as $container => $info) {
                    $units[] = [
                        'enclosure_id' => $container,
                        'model' => 'Unknown Expansion',
                        'serial' => '',
                        'firmware' => '',
                        'bay_count' => max($info['bay_count'], 5),
                        'installed_drives' => $info['bay_count'],
                        'drives' => $info['drives'],
                        'status' => 'detected',
                        'power_status' => 'unknown',
                    ];
                }
            }

            // If we found expansion devices by naming pattern, create unit entry
            if (empty($units) && !empty($expansionDevices)) {
                foreach ($expansionDevices as $unitId => $info) {
                    $units[] = [
                        'enclosure_id' => $unitId,
                        'model' => 'Expansion Unit (DX513/DX517 equiv)',
                        'serial' => '',
                        'firmware' => '',
                        'bay_count' => max($info['bay_count'], 5),
                        'installed_drives' => $info['bay_count'],
                        'drives' => $info['drives'],
                        'status' => 'active',
                        'power_status' => 'unknown',
                    ];
                }
            }

            if (!empty($units)) {
                $sourceFile = 'dsm/result/load_info.result';
                $timestamp = date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result'));
            }
        }

        // Try 2: Fallback to synoinfo.conf for expansion capability detection
        if (empty($units)) {
            $synoinfo = $this->parseSynoinfo();
            $hasExpansion = false;
            $expansionType = '';
            $maxExpansionBays = 0;

            foreach ($synoinfo as $key => $value) {
                if (preg_match('/expansion.*bays?/i', $key)) {
                    $maxExpansionBays = (int)$value;
                }
                if (preg_match('/expansion.*type/i', $key) && !empty($value)) {
                    $hasExpansion = true;
                    $expansionType = $value;
                }
            }

            if ($hasExpansion || $maxExpansionBays > 0) {
                $units[] = [
                    'enclosure_id' => 'expansion_unit_1',
                    'model' => $expansionType ?: 'Unknown Expansion Unit',
                    'serial' => '',
                    'firmware' => '',
                    'bay_count' => $maxExpansionBays ?: 5,
                    'installed_drives' => 0,
                    'drives' => [],
                    'status' => 'capable',
                    'power_status' => 'unknown',
                ];
                $sourceFile = 'dsm/etc/synoinfo.conf';
                $timestamp = date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/etc/synoinfo.conf'));
            }
        }

        if (empty($units)) {
            return null;
        }

        return [
            'data' => $units,
            'file' => $sourceFile,
            'timestamp' => $timestamp,
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
     * Classify RAID array as internal system or user-created
     * md0 = Internal System RAID 1 (root filesystem)
     * md1 = Internal Swap RAID 1
     * mdX (X>=2) = User-created RAID arrays
     */
    private function classifyRaidArray(string $mdName): string
    {
        if (in_array($mdName, ['md0', 'md1'], true)) {
            return 'internal';
        }
        return 'user';
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
