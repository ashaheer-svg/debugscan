<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

class ExtractionConfigService
{
    private PDO $pdo;

    /**
     * Hardcoded metadata for each extraction section.
     * This drives the UI labels, descriptions, and token impact badges.
     */
    public const SECTION_METADATA = [
        // Group 1: Core Identity
        'hardware' => [
            'group'       => 'Core Identity',
            'label'       => 'Hardware Identity',
            'parser'      => 'HardwareParser',
            'description' => 'Extracts the NAS model, serial number, CPU type/cores, total/available RAM, uptime, and physical location. This is the forensic identifier anchor — every finding is linked to this device profile.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => true,
        ],
        'version' => [
            'group'       => 'Core Identity',
            'label'       => 'DSM Version',
            'parser'      => 'VersionParser',
            'description' => 'Reads the DSM product version and build number from the VERSION file. Required for the hardware parser to correctly locate DSM 7.x vs 6.x file paths. Disabling this can break other parsers.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => true,
        ],

        // Group 2: Storage Subsystem
        'disks' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'Disk Telemetry',
            'parser'      => 'DiskParser',
            'description' => 'Reads authoritative disk metadata from load_info.result and per-disk runtime files. Includes model, serial, firmware, temperature, SMART status, bad sector count, uncorrectable errors (UNC), reset/timeout failure weights, and SSD wear level for every installed drive.',
            'impact'      => 'MEDIUM',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'raid' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'RAID Array Status',
            'parser'      => 'RaidParser',
            'description' => 'Parses /proc/mdstat to determine the current RAID array state (active, degraded, recovering), active drive membership, and any bitmap reconstruction progress. Critical for identifying storage redundancy failures.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'volumes' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'Volume Status',
            'parser'      => 'VolumeParser',
            'description' => 'Reads the current state of all Synology storage volumes, including mount status, filesystem type, and capacity. Provides context for storage pool health.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'btrfs' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'Btrfs Filesystem Integrity',
            'parser'      => 'BtrfsScrubParser',
            'description' => 'Parses the btrfs scrub log to find completed integrity checks, errors (corrupt data blocks), and the age of the last scrub. Essential for detecting silent data corruption on Btrfs-formatted volumes.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'storage_util' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'Storage Capacity (df)',
            'parser'      => 'DfResultParser',
            'description' => 'Reads df.result to show the used/available space for every mounted filesystem (/, /volume1, etc.) in GB and percentage. Identifies volumes approaching capacity, which can cause write failures and btrfs performance degradation.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'disk_io' => [
            'group'       => 'Storage Subsystem',
            'label'       => 'Disk I/O Performance',
            'parser'      => 'DiskstatsParser',
            'description' => 'Parses /proc/diskstats to calculate average read/write latency (ms) per device since last reboot. High latency values indicate drives that are struggling — either due to hardware degradation, RAID rebuild load, or I/O saturation.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],

        // Group 3: System Health
        'logs' => [
            'group'       => 'System Health',
            'label'       => 'System Log Events',
            'parser'      => 'LogParser',
            'description' => 'Scans the last N lines of messages, dmesg, and synosys.log for critical keywords (error, fail, critical, panic, degraded, unhealthy). The most recent matching events are included in the AI context. Reducing Max Events significantly lowers token usage.',
            'impact'      => 'MEDIUM',
            'has_limit'   => true,
            'limit_label' => 'Max Events',
            'always_on'   => false,
        ],
        'dstate' => [
            'group'       => 'System Health',
            'label'       => 'D-State Process Monitor',
            'parser'      => 'DStateParser',
            'description' => 'Detects processes stuck in uninterruptible sleep (D-state), which is the kernel\'s indicator of a blocked I/O operation. Each event includes a full kernel stack trace. These traces are very large — set a low Max Events limit (5–10) to control token usage.',
            'impact'      => 'HIGH',
            'has_limit'   => true,
            'limit_label' => 'Max Events',
            'always_on'   => false,
        ],
        'system_load' => [
            'group'       => 'System Health',
            'label'       => 'System Load (top)',
            'parser'      => 'TopResultParser',
            'description' => 'Parses top.result for load averages (1/5/15 min), CPU breakdown (user, system, iowait %), memory usage, and zombie process count. The iowait% value is one of the most diagnostic indicators for I/O-related performance issues.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'memory_util' => [
            'group'       => 'System Health',
            'label'       => 'Memory & VM Statistics',
            'parser'      => 'VmstatParser',
            'description' => 'Reads /proc/meminfo and /proc/vmstat — two of the most verbose kernel data sources. Provides swap usage, OOM risk assessment, and memory pressure detection (pgscan_direct). Contains 150+ raw kernel counter keys. Disable for L1 scans where memory is not the primary concern to save significant tokens.',
            'impact'      => 'HIGH',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'network' => [
            'group'       => 'System Health',
            'label'       => 'Network Configuration',
            'parser'      => 'NetworkParser',
            'description' => 'Extracts network interface IP addresses and netmask from ifconfig.result, and the default gateway from route.result. Low token cost. Useful for identifying misconfigured network settings but rarely affects storage-focused L1 diagnoses.',
            'impact'      => 'LOW',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'network_hardware' => [
            'group'       => 'System Health',
            'label'       => 'Network Hardware Health',
            'parser'      => 'NetworkHardwareParser',
            'description' => 'Analyzes ethtool metrics, CRC errors, and connection states to detect failing cables, transceiver issues, and link flapping. Essential for diagnosing intermittent connectivity and physical layer packet loss.',
            'impact'      => 'MEDIUM',
            'has_limit'   => false,
            'always_on'   => false,
        ],

        // Group 4: SQLite Forensic Databases
        'db_system_events' => [
            'group'       => 'SQLite Forensic Databases',
            'label'       => 'System Event Log (SYNOSYSDB)',
            'parser'      => 'DatabaseParser',
            'description' => 'Queries the .SYNOSYSDB SQLite database for system-level events from the past 12 months. Events are filtered by relevance (RAID state changes, disk events, improper shutdowns, power supply alerts). The largest single data source — setting a Max Rows limit here has the most impact on prompt size.',
            'impact'      => 'HIGH',
            'has_limit'   => true,
            'limit_label' => 'Max Rows',
            'always_on'   => false,
        ],
        'db_disk_health' => [
            'group'       => 'SQLite Forensic Databases',
            'label'       => 'Disk Health Predictions (SYNODISKHEALTHDB)',
            'parser'      => 'DatabaseParser',
            'description' => 'Reads the disk_error table (bad sectors, UNC counts per drive) and failure prediction scores from the .SYNODISKHEALTHDB database. This is the most direct evidence of physical drive failure risk. Usually small in row count. Recommended to keep ON.',
            'impact'      => 'MEDIUM',
            'has_limit'   => false,
            'always_on'   => false,
        ],
        'db_connection_logs' => [
            'group'       => 'SQLite Forensic Databases',
            'label'       => 'Connection & Access Logs (SYNOCONNDB)',
            'parser'      => 'DatabaseParser',
            'description' => 'Queries .SYNOCONNDB for authentication warnings, errors, and brute-force login attempt summaries. Most relevant for security investigations. For pure hardware diagnostics (L1), this section is typically unnecessary. Disabled by default.',
            'impact'      => 'HIGH',
            'has_limit'   => true,
            'limit_label' => 'Max Rows',
            'always_on'   => false,
        ],
        'db_disk_events' => [
            'group'       => 'SQLite Forensic Databases',
            'label'       => 'Disk Event Log (SYNODISKDB)',
            'parser'      => 'DatabaseParser',
            'description' => 'Reads the .SYNODISKDB database for per-drive event logs — disk insertions, removals, timeout events, and I/O errors grouped by drive. Provides a longitudinal view of each drive\'s reliability history. Set Max Rows to control how much history is included.',
            'impact'      => 'HIGH',
            'has_limit'   => true,
            'limit_label' => 'Max Rows',
            'always_on'   => false,
        ],
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Load all extraction config rows from DB for a specific plan and merge with metadata.
     * Returns a keyed array: section_key => [metadata + db values]
     */
    public function getConfig(string $planId): array
    {
        $stmt = $this->pdo->prepare("SELECT section_key, is_enabled, max_rows, updated_at FROM extraction_config WHERE report_plan_id = :pid ORDER BY section_key");
        $stmt->execute(['pid' => $planId]);
        
        $dbRows = [];
        foreach ($stmt->fetchAll() as $row) {
            $dbRows[$row['section_key']] = $row;
        }

        $result = [];
        foreach (self::SECTION_METADATA as $key => $meta) {
            $db = $dbRows[$key] ?? [];
            $result[$key] = array_merge($meta, [
                'section_key'    => $key,
                'report_plan_id' => $planId,
                'is_enabled'     => isset($db['is_enabled']) ? (bool)$db['is_enabled'] : true,
                'max_rows'       => $db['max_rows'] ?? null,
                'updated_at'     => $db['updated_at'] ?? null,
            ]);
        }
        return $result;
    }

    /**
     * Returns only the runtime config (key => [is_enabled, max_rows]) for the scanner.
     * @param string $planId The UUID of the report plan
     */
    public function getRuntimeConfig(string $planId): array
    {
        $stmt = $this->pdo->prepare("SELECT section_key, is_enabled, max_rows FROM extraction_config WHERE report_plan_id = :pid");
        $stmt->execute(['pid' => $planId]);
        $config = [];
        foreach ($stmt->fetchAll() as $row) {
            $config[$row['section_key']] = [
                'is_enabled' => (bool)$row['is_enabled'],
                'max_rows'   => $row['max_rows'] !== null ? (int)$row['max_rows'] : null,
            ];
        }
        return $config;
    }

    /**
     * Save a single section's settings for a specific report plan.
     */
    public function saveSection(string $key, string $planId, bool $enabled, ?int $maxRows): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO extraction_config (section_key, report_plan_id, is_enabled, max_rows, updated_at)
            VALUES (:key, :pid, :enabled, :max_rows, NOW())
            ON CONFLICT (section_key, report_plan_id) DO UPDATE
                SET is_enabled = EXCLUDED.is_enabled,
                    max_rows   = EXCLUDED.max_rows,
                    updated_at = NOW()
        ");
        $stmt->execute([
            'key'      => $key,
            'pid'      => $planId,
            'enabled'  => $enabled ? 1 : 0,
            'max_rows' => $maxRows,
        ]);
    }

    /**
     * Save all sections from a bulk POST payload for a specific plan.
     * $data: [section_key => ['enabled' => '1', 'max_rows' => '75']]
     */
    public function saveAll(string $planId, array $data): void
    {
        foreach (self::SECTION_METADATA as $key => $meta) {
            if ($meta['always_on']) continue;

            $enabled = isset($data[$key]['enabled']) && $data[$key]['enabled'] === '1';
            $maxRows = null;
            if ($meta['has_limit'] && isset($data[$key]['max_rows']) && is_numeric($data[$key]['max_rows'])) {
                $maxRows = max(1, (int)$data[$key]['max_rows']);
            }
            $this->saveSection($key, $planId, $enabled, $maxRows);
        }
    }
}
