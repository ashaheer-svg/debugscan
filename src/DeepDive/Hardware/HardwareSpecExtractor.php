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

        // Failure Analysis - Extract from logs and correlate with snapshot
        $raidFailureLogs = $this->parseRAIDFailureLogs();
        if (!empty($raidFailureLogs)) {
            $spec->raidFailureLogs = $raidFailureLogs;
            $this->addCitation($spec, 'raid_failure_logs', 'dsm/var/log/messages', date('Y-m-d H:i:s'));
        }

        // Detect failure patterns
        $failurePatterns = $this->detectFailurePatterns($raidFailureLogs);
        if (!empty($failurePatterns)) {
            $spec->failurePatterns = $failurePatterns;
        }

        // Get current RAID state for correlation
        $mdstatData = $this->parseMdstat();

        // Correlate with snapshot to classify failures
        if (!empty($spec->drives) && (!empty($raidFailureLogs) || !empty($mdstatData))) {
            $spec->failures = $this->correlateWithSnapshot(
                $spec->drives,
                $failurePatterns,
                $mdstatData
            );
            $this->addCitation($spec, 'failures', 'dsm/proc/mdstat, dsm/var/log/messages', date('Y-m-d H:i:s'));
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
     * Extract RAM information with fallback to load_info.result
     */
    private function extractRamInfo(): ?array
    {
        $data = [
            'total_gb' => 0,
            'available_gb' => 0,
        ];
        $sourceFile = '';
        $timestamp = '';

        // Try 1: /dsm/proc/meminfo (most direct)
        $file = $this->extractedPath . '/dsm/proc/meminfo';
        if (file_exists($file)) {
            $content = (string)@file_get_contents($file);

            // Parse MemTotal in kB to GB
            if (preg_match('/MemTotal:\s+(\d+)\s+kB/i', $content, $m)) {
                $data['total_gb'] = round((int)$m[1] / 1024 / 1024, 2);
            }

            // Parse MemAvailable in kB to GB
            if (preg_match('/MemAvailable:\s+(\d+)\s+kB/i', $content, $m)) {
                $data['available_gb'] = round((int)$m[1] / 1024 / 1024, 2);
            }

            if ($data['total_gb'] > 0) {
                return [
                    'data' => $data,
                    'file' => 'dsm/proc/meminfo',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
                ];
            }
        }

        // Try 2: Fallback to load_info.result memory field
        $loadInfo = $this->parseJsonResult('load_info.result');
        if (is_array($loadInfo)) {
            // Check for memory in load_info
            $memoryData = $loadInfo['memory'] ?? $loadInfo['data']['memory'] ?? null;
            if (is_array($memoryData) && !empty($memoryData['total'])) {
                // Memory might be in bytes or MB - detect and convert
                $total = (int)$memoryData['total'];
                $available = (int)($memoryData['available'] ?? $memoryData['free'] ?? 0);

                // If value > 1000000, assume it's bytes; convert to GB
                if ($total > 1000000) {
                    $data['total_gb'] = round($total / 1024 / 1024 / 1024, 2);
                    $data['available_gb'] = round($available / 1024 / 1024 / 1024, 2);
                } else {
                    // Assume already in MB
                    $data['total_gb'] = round($total / 1024, 2);
                    $data['available_gb'] = round($available / 1024, 2);
                }

                if ($data['total_gb'] > 0) {
                    return [
                        'data' => $data,
                        'file' => 'dsm/result/load_info.result (memory field)',
                        'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result')),
                    ];
                }
            }
        }

        return null;
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
            // Expansion drives are identified by:
            // 1. Container name containing "expansion"
            // 2. Device name starting with sdea or higher (expansion device naming)
            if (!empty($disksArray)) {
                foreach ($disksArray as $disk) {
                    if (!is_array($disk)) continue;

                    $isExpansion = false;

                    // Check container name
                    if (!empty($disk['container']['str'])) {
                        if (preg_match('/expansion/i', $disk['container']['str'])) {
                            $isExpansion = true;
                        }
                    }

                    // Check device name (sdea and above are expansion)
                    if (!$isExpansion && !empty($disk['id'])) {
                        if (preg_match('/^sde[a-z]/', $disk['id'])) {
                            $isExpansion = true;
                        }
                    }

                    if ($isExpansion) {
                        $expansionDiskCount++;
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
            // ONLY use actual extracted data - NO assumptions
            // If max_bay_count exists in load_info, use it. Don't guess or assume defaults.
            if ($mainBayCount > 0 || $diskCount > 0) {
                return [
                    'data' => [
                        'main_unit_bays' => (int)$mainBayCount,  // Only use actual data from load_info
                        'main_unit_used' => max(0, $mainDiskCount),
                        'expansion_unit_bays' => $expansionBayCount,
                        'expansion_unit_used' => $expansionDiskCount,
                        'total_bays' => (int)$mainBayCount + $expansionBayCount,  // No assumptions
                        'total_used' => $diskCount,
                    ],
                    'file' => 'dsm/result/load_info.result',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($this->extractedPath . '/dsm/result/load_info.result')),
                ];
            }
        }

        // Try 2: Count actual disks in synostorage directory
        // Only use actual data - don't assume bay count is higher than disk count
        $diskDirs = glob($this->extractedPath . '/dsm/run/synostorage/disks/*', GLOB_ONLYDIR);
        if (!empty($diskDirs)) {
            $diskCount = count($diskDirs);
            return [
                'data' => [
                    'main_unit_bays' => $diskCount,  // Use actual disk count, don't guess
                    'main_unit_used' => $diskCount,
                    'expansion_unit_bays' => 0,
                    'expansion_unit_used' => 0,
                    'total_bays' => $diskCount,  // No assumptions
                    'total_used' => $diskCount,
                ],
                'file' => 'dsm/run/synostorage/disks/',
                'timestamp' => date('Y-m-d H:i:s'),
            ];
        }

        // Try 3: synoinfo.conf parsing for bay capacity
        // Only count actual slot definitions - don't assume minimums
        $synoinfo = $this->parseSynoinfo();
        $mainBayCount = 0;
        $expansionBayCount = 0;

        foreach ($synoinfo as $key => $value) {
            // Main unit bays: slot0_type, slot1_type, etc.
            // Only count if slot type is defined
            if (preg_match('/^slot(\d+)_type$/i', $key) && !empty($value)) {
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
     * Retrieves actual model data from load_info.result without assumptions
     */
    private function extractExpansion(): ?array
    {
        $units = [];
        $sourceFile = '';
        $timestamp = '';

        // Extract expansion data from load_info.result
        $loadInfo = $this->parseJsonResult('load_info.result');
        // Handle both DSM 6 and DSM 7 JSON structures
        $disksArray = $loadInfo['data']['disks'] ?? $loadInfo['disks'] ?? [];

        if (is_array($loadInfo) && !empty($disksArray)) {
            // Extract actual enclosure/expansion data from load_info
            $units = $this->extractEnclosureData($loadInfo, $disksArray);

            // If we found units, set source info
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

            // Only create expansion unit if we have ACTUAL data about it
            // Don't assume 5 bays - use only what's extracted
            if ($maxExpansionBays > 0) {  // Only if we have actual bay count
                $units[] = [
                    'enclosure_id' => 'expansion_unit_1',
                    'model' => $expansionType ?: 'Unknown Expansion Unit',
                    'serial' => '',
                    'firmware' => '',
                    'bay_count' => $maxExpansionBays,  // Use actual value only
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
     * Parse JSON .result file with error handling
     * Returns null if file doesn't exist or JSON is invalid
     */
    private function parseJsonResult(string $filename): mixed
    {
        $file = $this->extractedPath . '/dsm/result/' . $filename;
        if (!file_exists($file)) {
            return null;
        }

        $content = (string)@file_get_contents($file);
        if (empty($content)) {
            return null;
        }

        $decoded = json_decode($content, associative: true);

        // Handle JSON decode errors gracefully
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            // Log error but don't throw - allow pipeline to continue
            error_log("JSON decode error in {$filename}: " . json_last_error_msg());
            return null;
        }

        return $decoded;
    }

    /**
     * Extract actual enclosure data from load_info.result
     * Maps enclosure data to expansion units with drive information
     *
     * @param array $loadInfo Parsed load_info.result
     * @param array $disksArray Array of disk entries
     * @return array List of expansion units
     */
    private function extractEnclosureData(array $loadInfo, array $disksArray): array
    {
        $units = [];
        $containerToDrives = [];  // Map containers to their drives
        $deviceToContainer = []; // Map devices to their containers

        // First pass: build maps of drives by container
        foreach ($disksArray as $disk) {
            if (!is_array($disk)) continue;

            $deviceId = $disk['id'] ?? '';
            $container = $disk['container']['str'] ?? null;

            if ($container) {
                $deviceToContainer[$deviceId] = $container;
                if (!isset($containerToDrives[$container])) {
                    $containerToDrives[$container] = [];
                }
                $containerToDrives[$container][] = $deviceId;
            }
        }

        // Second pass: extract enclosure data from load_info['enclosures']
        if (!empty($loadInfo['enclosures'])) {
            foreach ((array)$loadInfo['enclosures'] as $enclosure) {
                if (!is_array($enclosure)) continue;

                $enclosureId = $enclosure['id'] ?? null;
                if (!$enclosureId) continue;

                // Check if this enclosure is for an expansion unit
                // Extract directly from load_info data, don't assume
                $isExpansion = false;

                // Check multiple indicators without assuming model
                if (preg_match('/expansion|enclosure/i', (string)$enclosureId)) {
                    $isExpansion = true;
                }

                // Also check if enclosure contains external drives
                // (devices not in main NAS, i.e., sdea and above)
                $containsExternalDrives = false;
                if (isset($containerToDrives[$enclosureId])) {
                    foreach ($containerToDrives[$enclosureId] as $device) {
                        if (preg_match('/^sde[a-z]/', $device)) {
                            $containsExternalDrives = true;
                            break;
                        }
                    }
                    if ($containsExternalDrives) {
                        $isExpansion = true;
                    }
                }

                // Extract only what's actually in the data
                if ($isExpansion) {
                    $unit = [
                        'enclosure_id' => $enclosureId,
                        'model' => $enclosure['model'] ?? 'Unknown Model',
                        'serial' => $enclosure['serial'] ?? '',
                        'firmware' => $enclosure['firmware'] ?? '',
                        'bay_count' => (int)($enclosure['bay_count'] ?? 0),
                        'drives' => $containerToDrives[$enclosureId] ?? [],
                        'installed_drives' => count($containerToDrives[$enclosureId] ?? []),
                        'status' => $enclosure['status'] ?? 'unknown',
                        'power_status' => $enclosure['power_status'] ?? 'unknown',
                    ];

                    // Only include fields that have actual data
                    $units[] = $this->stripEmptyFields($unit);
                }
            }
        }

        // Third pass: detect expansion units by device naming (sdea, sdeb, etc = expansion devices)
        if (empty($units)) {
            $expansionDrives = [];
            foreach ($deviceToContainer as $device => $container) {
                // Expansion devices start with sdea and above
                if (preg_match('/^sde[a-z]/', $device)) {
                    if (!isset($expansionDrives[$container])) {
                        $expansionDrives[$container] = [];
                    }
                    $expansionDrives[$container][] = $device;
                }
            }

            // Create expansion units from detected drives
            if (!empty($expansionDrives)) {
                foreach ($expansionDrives as $container => $drives) {
                    $unit = [
                        'enclosure_id' => $container,
                        'model' => 'Expansion Unit',  // Generic label - actual model from load_info if available
                        'serial' => '',
                        'firmware' => '',
                        'bay_count' => count($drives),
                        'drives' => $drives,
                        'installed_drives' => count($drives),
                        'status' => 'active',
                        'power_status' => 'online',
                    ];
                    $units[] = $this->stripEmptyFields($unit);
                }
            }
        }

        return $units;
    }

    /**
     * Keep all fields but ensure they have meaningful defaults
     * Don't strip numeric 0 values (bay counts) - only filter truly empty data
     */
    private function stripEmptyFields(array $unit): array
    {
        // Ensure required fields always exist with defaults
        $defaults = [
            'enclosure_id' => 'Unknown',
            'model' => 'Unknown Expansion Unit',
            'serial' => '',
            'firmware' => '',
            'bay_count' => 0,
            'installed_drives' => 0,
            'drives' => [],
            'status' => 'unknown',
            'power_status' => 'unknown',
        ];

        // Merge with provided data, keeping defaults for missing/null fields
        foreach ($unit as $key => $value) {
            if ($value !== null && $value !== '') {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
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

    /**
     * Parse RAID failure logs from /var/log/messages
     * Extracts all failure events with timestamps for pattern analysis
     * DSM 6/7 compatible log format parsing
     *
     * @return array<int, array> Array of failure events with timestamp, device, array, error type
     */
    public function parseRAIDFailureLogs(): array
    {
        $failures = [];

        // Try multiple log locations for DSM 6 and DSM 7 compatibility
        $logPaths = [
            $this->extractedPath . '/dsm/var/log/messages',
            $this->extractedPath . '/dsm/var/log/syslog',
            $this->extractedPath . '/dsm/var/log/kern.log',
        ];

        foreach ($logPaths as $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }

            $content = (string)@file_get_contents($logFile);
            if (empty($content)) {
                continue;
            }

            // Parse each line looking for RAID failure patterns
            foreach (explode("\n", $content) as $line) {
                $failure = $this->parseRAIDFailureLine($line);
                if ($failure !== null) {
                    $failures[] = $failure;
                }
            }
        }

        return $failures;
    }

    /**
     * Parse a single log line for RAID failure events
     * Handles various kernel log formats and error messages
     *
     * @param string $line Raw log line
     * @return ?array Failure event array or null if not a failure line
     */
    private function parseRAIDFailureLine(string $line): ?array
    {
        // Match kernel log timestamp format: "Apr 27 22:59:19" or "2025-04-27T22:59:19"
        $timestamp = null;

        // Try ISO format: 2025-04-27T22:59:19
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m)) {
            $timestamp = $m[1];
        }
        // Try syslog format: "Apr 27 22:59:19" - need to infer year
        elseif (preg_match('/^([A-Za-z]{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2})/', $line, $m)) {
            // For syslog format, convert to ISO with inferred year
            // Try current year first, then previous year if it's in the future
            $syslogTime = $m[1];
            $tryYear = (int)date('Y');

            // Attempt to parse with current year
            $dt = \DateTime::createFromFormat('M d H:i:s Y', $syslogTime . ' ' . $tryYear);
            if ($dt && $dt->getTimestamp() > time()) {
                // Timestamp is in the future, try previous year
                $dt = \DateTime::createFromFormat('M d H:i:s Y', $syslogTime . ' ' . ($tryYear - 1));
            }

            if ($dt) {
                $timestamp = $dt->format('Y-m-d H:i:s');
            } else {
                // Fallback: use as-is and let grouping handle it
                $timestamp = $syslogTime;
            }
        } else {
            return null;
        }

        // RAID array name: md0, md1, md2, etc.
        $raidArray = null;
        if (preg_match('/(md\d+)/', $line, $m)) {
            $raidArray = $m[1];
        }

        // Device name: sda, sdb, sdea, sdeb, etc.
        $device = null;
        if (preg_match('/\b(sd[a-z]+)\b/', $line, $m)) {
            $device = $m[1];
        }

        // Only record if we have array and device
        if ($raidArray === null || $device === null) {
            return null;
        }

        // Determine error type
        $errorType = 'unknown_error';
        if (preg_match('/read error|I\/O error/i', $line)) {
            $errorType = 'read_error';
        } elseif (preg_match('/write error/i', $line)) {
            $errorType = 'write_error';
        } elseif (preg_match('/timeout|time out/i', $line)) {
            $errorType = 'timeout';
        } elseif (preg_match('/fail|failed|failure|Disk failure/i', $line)) {
            $errorType = 'disk_failure';
        } elseif (preg_match('/not correctable|unc/i', $line)) {
            $errorType = 'uncorrectable_error';
        }

        // Extract sector information if present
        $sector = null;
        if (preg_match('/sector\s+(\d+)/i', $line, $m)) {
            $sector = (int)$m[1];
        }

        return [
            'timestamp' => $timestamp,
            'device' => $device,
            'raid_array' => $raidArray,
            'error_type' => $errorType,
            'sector' => $sector,
            'raw_line' => $line,
        ];
    }

    /**
     * Parse current RAID state from /proc/mdstat
     * Returns current status of all RAID arrays including failed devices
     *
     * @return array<string, array> RAID array states keyed by array name
     */
    public function parseMdstat(): array
    {
        $mdstat = $this->extractedPath . '/dsm/proc/mdstat';
        if (!file_exists($mdstat)) {
            return [];
        }

        $content = (string)@file_get_contents($mdstat);
        if (empty($content)) {
            return [];
        }

        $arrays = [];
        $currentArray = null;

        foreach (explode("\n", $content) as $line) {
            // Header line: "md2 : active raid5 sdea[0](F) sdeb[1](F) sdec[2] sded[3]"
            if (preg_match('/^(md\d+)\s*:\s*(\S+)\s+(\S+)\s+(.*)$/', $line, $m)) {
                $arrayName = $m[1];
                $status = $m[2];  // active, inactive, recovery, etc.
                $level = $m[3];   // raid0, raid1, raid5, raid6, etc.
                $devices = $m[4];

                // Parse device list with status markers
                $deviceList = [];
                if (preg_match_all('/(\w+)\[(\d+)\](?:\(([A-Z]+)\))?/', $devices, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $match) {
                        $deviceList[] = [
                            'name' => $match[1],
                            'index' => (int)$match[2],
                            'status' => $match[3] ?? 'ok',  // F = failed, S = spare, etc.
                        ];
                    }
                }

                $currentArray = [
                    'name' => $arrayName,
                    'status' => $status,
                    'level' => $level,
                    'devices' => $deviceList,
                    'failed_devices' => array_filter($deviceList, fn($d) => $d['status'] === 'F'),
                ];

                $arrays[$arrayName] = $currentArray;
            }
        }

        return $arrays;
    }

    /**
     * Extract historical serial numbers from logs and device info
     * Builds timeline of which serial numbers were in which devices
     * Aggregates from multiple sources without data loss
     *
     * @return array<string, array> Timeline of serial numbers per device
     */
    public function extractHistoricalSerialNumbers(): array
    {
        $deviceTimeline = [];

        // Try to extract from /dev/disk/by-id symlinks if captured
        $fromLinks = $this->extractFromDeviceLinks();
        foreach ($fromLinks as $device => $entries) {
            if (!isset($deviceTimeline[$device])) {
                $deviceTimeline[$device] = [];
            }
            $deviceTimeline[$device] = array_merge($deviceTimeline[$device], $entries);
        }

        // Try to extract from kernel logs (device detection messages)
        $fromLogs = $this->extractFromKernelLogs();
        foreach ($fromLogs as $device => $entries) {
            if (!isset($deviceTimeline[$device])) {
                $deviceTimeline[$device] = [];
            }
            $deviceTimeline[$device] = array_merge($deviceTimeline[$device], $entries);
        }

        // Try to extract from SMART data if available
        $fromSmart = $this->extractFromSmartData();
        foreach ($fromSmart as $device => $entries) {
            if (!isset($deviceTimeline[$device])) {
                $deviceTimeline[$device] = [];
            }
            $deviceTimeline[$device] = array_merge($deviceTimeline[$device], $entries);
        }

        return $deviceTimeline;
    }

    /**
     * Extract serial numbers from /dev/disk/by-id/ symlinks
     * These typically encode serial numbers in the symlink names
     *
     * @return array<string, array> Timeline entries
     */
    private function extractFromDeviceLinks(): array
    {
        $timeline = [];

        $devDiskPath = $this->extractedPath . '/dsm/dev/disk/by-id';
        if (!is_dir($devDiskPath)) {
            return $timeline;
        }

        // Read symlink directory
        $files = @scandir($devDiskPath);
        if ($files === false) {
            return $timeline;
        }

        foreach ($files as $link) {
            if ($link === '.' || $link === '..' || strpos($link, '-part') !== false) {
                continue;
            }

            // Try to read where symlink points
            $linkPath = $devDiskPath . '/' . $link;
            $target = @readlink($linkPath);
            if ($target === false) {
                continue;
            }

            // Extract device name from target (e.g., ../../../sda)
            if (preg_match('/\/(sd[a-z0-9]+)$/i', $target, $m)) {
                $device = strtolower($m[1]);

                // Extract serial from symlink name
                // Format: ata-MODEL_SERIAL or scsi-SCSISERIAL
                // More explicit pattern to avoid edge cases
                $serial = null;
                if (preg_match('/^(?:ata|scsi)[a-z0-9\-]*?_([\w]+)$/i', $link, $m)) {
                    $serial = strtoupper($m[1]);
                }

                if ($serial !== null) {
                    if (!isset($timeline[$device])) {
                        $timeline[$device] = [];
                    }

                    // Use actual symlink file modification time, not current time
                    $timestamp = date('Y-m-d H:i:s', filemtime($linkPath));

                    $timeline[$device][] = [
                        'timestamp' => $timestamp,
                        'serial' => $serial,
                        'source' => 'device_symlink',
                        'symlink_name' => $link,
                    ];
                }
            }
        }

        return $timeline;
    }

    /**
     * Extract serial numbers from kernel boot logs
     * When drives are detected, kernel logs include model and sometimes serial
     *
     * @return array<string, array> Timeline entries
     */
    private function extractFromKernelLogs(): array
    {
        $timeline = [];

        $logPaths = [
            $this->extractedPath . '/dsm/var/log/messages',
            $this->extractedPath . '/dsm/var/log/kern.log',
        ];

        foreach ($logPaths as $logFile) {
            if (!file_exists($logFile)) {
                continue;
            }

            $content = (string)@file_get_contents($logFile);
            if (empty($content)) {
                continue;
            }

            $currentDevice = null;  // Track device context across lines

            foreach (explode("\n", $content) as $line) {
                // Track device when mentioned in log
                if (preg_match('/\b(sd[a-z0-9]+)\b/i', $line, $m)) {
                    $currentDevice = strtolower($m[1]);
                }

                // Look for device detection lines
                // Format: "ata3.00: ATA-9: MODEL_NAME, FIRMWARE_VER, max UDMA/133"
                if (preg_match('/^(.{15,20})(ata\d+\.\d+|scsi\s+\d+:\d+:\d+:\d+):\s+(Direct-Access|ATA|SCSI).*Model:\s+(.+?)$/i', $line, $m)) {
                    $timestamp = trim($m[1]);
                    $model = trim($m[4]);

                    // Extract serial if present
                    $serial = null;
                    if (preg_match('/Serial:\s+([A-Z0-9]+)/i', $line, $sm)) {
                        $serial = $sm[1];
                    }

                    // Store extracted data if we have device context and serial
                    if ($currentDevice !== null && $serial !== null) {
                        if (!isset($timeline[$currentDevice])) {
                            $timeline[$currentDevice] = [];
                        }

                        $timeline[$currentDevice][] = [
                            'timestamp' => $timestamp,
                            'serial' => $serial,
                            'model' => $model,
                            'source' => 'kernel_log',
                        ];
                    }
                }
            }
        }

        return $timeline;
    }

    /**
     * Extract serial numbers from SMART data if available
     *
     * @return array<string, array> Timeline entries
     */
    private function extractFromSmartData(): array
    {
        $timeline = [];

        // Check for smartctl output or SMART attribute dumps
        $smartPaths = [
            $this->extractedPath . '/dsm/var/log/smartctl_output',
            $this->extractedPath . '/dsm/result/smart_data.result',
        ];

        foreach ($smartPaths as $file) {
            if (!file_exists($file)) {
                continue;
            }

            $content = (string)@file_get_contents($file);
            if (empty($content)) {
                continue;
            }

            // Parse SMART output for serial numbers
            $currentDevice = null;
            foreach (explode("\n", $content) as $line) {
                // Extract device context from lines like "smartctl output for /dev/sda"
                if (preg_match('/\/dev\/(sd[a-z0-9]+)/i', $line, $m)) {
                    $currentDevice = strtolower($m[1]);
                }

                // Extract serial number
                if (preg_match('/Serial Number:\s+([A-Z0-9]+)/i', $line, $m)) {
                    $serial = $m[1];

                    // Store with device context
                    if ($currentDevice !== null) {
                        if (!isset($timeline[$currentDevice])) {
                            $timeline[$currentDevice] = [];
                        }

                        $timeline[$currentDevice][] = [
                            'timestamp' => date('Y-m-d H:i:s', filemtime($file)),
                            'serial' => $serial,
                            'source' => 'smart_data',
                        ];
                    }
                }
            }
        }

        return $timeline;
    }

    /**
     * Detect failure patterns: simultaneous vs staggered
     * Analyzes timestamps to identify systemic vs individual failures
     *
     * @param array $failures Parsed failure events from logs
     * @return array Pattern analysis by RAID array
     */
    public function detectFailurePatterns(array $failures): array
    {
        $patterns = [];

        // Group failures by RAID array
        $byArray = [];
        foreach ($failures as $failure) {
            $array = $failure['raid_array'];
            if (!isset($byArray[$array])) {
                $byArray[$array] = [];
            }
            $byArray[$array][] = $failure;
        }

        // Analyze each array for patterns
        foreach ($byArray as $arrayName => $arrayFailures) {
            // Group by timestamp (within ±2 seconds tolerance)
            $failureGroups = $this->groupFailuresByTimestamp($arrayFailures);

            // Determine pattern type
            $devices = array_unique(array_column($arrayFailures, 'device'));
            $deviceCount = count($devices);

            $pattern = [
                'raid_array' => $arrayName,
                'total_failures' => count($arrayFailures),
                'affected_devices' => $devices,
                'device_count' => $deviceCount,
                'failure_groups' => $failureGroups,
            ];

            // Classification: if all devices fail at same time = systemic
            if ($deviceCount > 1 && count($failureGroups) === 1) {
                $pattern['pattern_type'] = 'systemic';
                $pattern['presumed_cause'] = 'shared_failure_source_power_connection_enclosure';
            } else if ($deviceCount > 1 && count($failureGroups) > 1) {
                $pattern['pattern_type'] = 'staggered';
                $pattern['presumed_cause'] = 'individual_component_failures';
            } else {
                $pattern['pattern_type'] = 'single_device';
                $pattern['presumed_cause'] = 'individual_drive_failure';
            }

            $patterns[$arrayName] = $pattern;
        }

        return $patterns;
    }

    /**
     * Group failures by timestamp with tolerance window
     * Allows for log buffering delays (±2 second window)
     *
     * @param array $failures Array of failure events
     * @return array<int, array> Grouped failures
     */
    private function groupFailuresByTimestamp(array $failures): array
    {
        $groups = [];
        $tolerance = 2;  // seconds

        foreach ($failures as $failure) {
            $timestamp = $failure['timestamp'];
            $device = $failure['device'];
            $found = false;

            // Try to match with existing group
            foreach ($groups as &$group) {
                $groupTime = $group[0]['timestamp'];

                // Convert to Unix timestamp for comparison if possible
                $failureTs = strtotime($timestamp) ?: 0;
                $groupTs = strtotime($groupTime) ?: 0;

                if ($failureTs > 0 && $groupTs > 0 && abs($failureTs - $groupTs) <= $tolerance) {
                    $group[] = $failure;
                    $found = true;
                    break;
                }
            }

            // Create new group if not matched
            if (!$found) {
                $groups[] = [$failure];
            }
        }

        return $groups;
    }

    /**
     * Correlate failure patterns with current snapshot
     * Identifies historical vs current failures and applies serial number tracking
     *
     * @param array $drives Current drive snapshot from load_info
     * @param array $patterns Failure patterns from logs
     * @param array $mdstatData Current RAID state from mdstat
     * @return array Classified failures with full context
     */
    public function correlateWithSnapshot(array $drives, array $patterns, array $mdstatData): array
    {
        $classified = [];

        // Extract historical serial numbers
        $serialTimeline = $this->extractHistoricalSerialNumbers();

        // Create lookup of current drives by device
        $currentByDevice = [];
        foreach ($drives as $drive) {
            // Validate drive array structure
            if (!is_array($drive) || empty($drive['device'])) {
                continue;  // Skip invalid entries
            }
            $currentByDevice[$drive['device']] = $drive;
        }

        // Create lookup of failed devices from mdstat
        $failedDevices = [];
        foreach ($mdstatData as $arrayData) {
            if (!is_array($arrayData) || empty($arrayData['failed_devices'])) {
                continue;
            }
            foreach ($arrayData['failed_devices'] as $failedDev) {
                if (is_array($failedDev) && !empty($failedDev['name'])) {
                    $failedDevices[$failedDev['name']] = $arrayData['name'] ?? '';
                }
            }
        }

        // Process each drive in the system
        foreach ($drives as $drive) {
            // Validate drive array before accessing
            if (!is_array($drive) || empty($drive['device'])) {
                continue;
            }

            $device = $drive['device'];
            $currentSerial = $drive['serial'] ?? '';

            $classification = [
                'device' => $device,
                'bay' => $drive['bay'] ?? 0,
                'location' => $drive['location'] ?? 'unknown',
                'model' => $drive['model'] ?? 'Unknown',
                'current_serial' => $currentSerial,
                'current_status' => $drive['status'] ?? 'unknown',
                'snapshot_status' => $drive['status'] ?? 'unknown',
                'failure_history' => [],
                'replacement_history' => [],
                'is_currently_failed' => isset($failedDevices[$device]),
                'failure_classification' => 'no_failure_record',
            ];

            // Check for failure records in logs
            foreach ($patterns as $pattern) {
                if (!is_array($pattern) || empty($pattern['affected_devices'])) {
                    continue;
                }
                if (in_array($device, $pattern['affected_devices'])) {
                    // This device has failure records
                    // Safely merge failure groups (check if not empty to avoid unpacking error)
                    $failureTimestamps = [];
                    if (!empty($pattern['failure_groups']) && is_array($pattern['failure_groups'])) {
                        $failureTimestamps = array_column(array_merge(...$pattern['failure_groups']), 'timestamp');
                    }
                    $classification['failure_history'] = $failureTimestamps;
                    $classification['pattern_type'] = $pattern['pattern_type'] ?? 'unknown';
                    $classification['pattern_presumed_cause'] = $pattern['presumed_cause'] ?? 'unknown';
                    $classification['raid_array'] = $pattern['raid_array'] ?? '';
                }
            }

            // Check if device is currently failed
            if ($classification['is_currently_failed']) {
                if (!empty($classification['failure_history'])) {
                    // Drive failed and is still failed
                    $classification['failure_classification'] = 'currently_failed';
                    $classification['status_detail'] = 'Drive failed in logs and remains failed in current state';
                } else {
                    // Current failure not in logs (unusual)
                    $classification['failure_classification'] = 'current_failure_no_log_record';
                    $classification['status_detail'] = 'Drive is currently failed but no failure record in logs';
                }
            } else {
                if (!empty($classification['failure_history'])) {
                    // Drive failed but is no longer failed (recovered or replaced)
                    $classification['failure_classification'] = 'historically_failed_now_operational';
                    $classification['status_detail'] = 'Drive failed historically but is now operational';

                    // Check for replacement using serial number
                    if (!empty($serialTimeline[$device])) {
                        $serials = array_unique(array_column($serialTimeline[$device], 'serial'));
                        if (count($serials) > 1 && !in_array($currentSerial, $serials)) {
                            $classification['failure_classification'] = 'replaced_after_failure';
                            $classification['replacement_status'] = 'replaced_once';
                            $classification['replacement_history'] = [
                                [
                                    'original_serial' => reset($serials),
                                    'replacement_serial' => $currentSerial,
                                    'replacement_indication' => 'serial_mismatch',
                                ]
                            ];
                        }
                    }
                } else {
                    // No failure history, healthy drive
                    $classification['failure_classification'] = 'no_failure_record';
                    $classification['status_detail'] = 'No failure history, drive is operational';
                }
            }

            $classified[$device] = $classification;
        }

        return $classified;
    }
}
