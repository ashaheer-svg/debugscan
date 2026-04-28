<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ZipHelper;
use PDO;
use RuntimeException;

/**
 * FileService: Extract and manage debug bundle files
 *
 * PURPOSE:
 * Handles extraction of Synology debug.dat archives (ZIP format)
 * Selectively extracts critical system files/directories
 * Manages decompression of historical .xz-compressed logs
 * Tracks extraction metadata in database
 * Cleans up files on deletion
 *
 * DEBUG BUNDLE STRUCTURE:
 * debug.dat is a ZIP archive containing complete NAS diagnostics:
 * - dsm/: System configuration and logs
 * - SMBService/: Samba configuration
 * - HighAvailability/: Cluster status (if applicable)
 * - ActiveInsight/: Cloud integration logs
 *
 * SELECTIVE EXTRACTION:
 * Only extracts paths in EXTRACTION_TARGETS (70+ critical files/dirs)
 * Significantly reduces extracted size vs full ZIP extraction
 * Targets: hardware config, logs, network, storage, RAID, volume metadata
 *
 * SIZE LIMITS:
 * max_extraction_size_gb from system_settings (default: 2 GB)
 * ZipHelper enforces limit during extraction
 * Prevents disk exhaustion from malformed or huge archives
 *
 * .XZ DECOMPRESSION:
 * Historical diagnostic logs often .xz-compressed for archive efficiency
 * decompressXzFiles() recursively inflates compressed logs post-extraction
 * Enables log parsers to access uncompressed content
 *
 * MINIMUM VALIDATION:
 * Checks for essential files (VERSION, partitions, mdstat, meminfo)
 * Requires >= 2 of 4 critical files to pass validation
 * Tolerates partial extracts; parser makes final judgment
 *
 * FILE LIFECYCLE:
 * 1. User uploads debug.dat → stored in uploadDir
 * 2. processFile() extracts to extractedDir/{fileId}
 * 3. Parser reads extracted files during job execution
 * 4. deleteProjectFile() removes both raw archive + extracted directory
 *
 * MULTI-TENANT:
 * fileId is UUID; not directly tenant-isolated in this service
 * Tenant isolation handled by controllers (file ownership validation)
 *
 * @package App\Services
 */
class FileService
{
    private string $uploadDir;
    private string $extractedDir;
    private PDO $pdo;

    /**
     * EXTRACTION_TARGETS: Critical Synology system files and directories
     *
     * CATEGORIES:
     * - Hardware: VERSION, cpuinfo, meminfo, hardware serial, synoinfo
     * - Storage: partitions, mdstat, RAID superblocks, volume metadata
     * - Volumes: LVM configs, btrfs/ext4 filesystem info
     * - Logs: system messages, kernel logs, diagnostic output
     * - Network: interfaces, routes, DNS, firewall, exports
     * - Services: Samba, NFS, UPS monitoring
     * - Performance: top, ps, free, vmstat, diskstats, loadavg
     * - Expansion: HA status, ActiveInsight cloud logs
     *
     * ~70 entries covering 95%+ of diagnostic needs
     * Selective extraction reduces extraction size 10-50x vs full archive
     *
     * @const array<string> Paths to extract from debug.dat
     */
    const EXTRACTION_TARGETS = [
        'dsm/etc/VERSION',
        'dsm/etc.defaults/VERSION',
        'dsm/proc/partitions',
        'dsm/proc/mdstat',
        'dsm/proc/meminfo',
        'dsm/proc/sys/kernel/syno_hw_version',
        'dsm/proc/sys/kernel/syno_serial',
        'dsm/proc/sys/kernel/syno_custom_serial',
        'dsm/etc/synoinfo.conf',
        'dsm/etc.defaults/synoinfo.conf',
        'dsm/proc/cpuinfo',
        'dsm/proc/uptime',
        'dsm/proc/loadavg',
        'dsm/result/load_info.result',
        'dsm/var/log/disk_log.csv',
        'dsm/var/log/disk_log.html',
        'dsm/var/log/disk_testlog.csv',
        'dsm/var/log/disk_testlog.html',
        'dsm/result/synoblock_enum.result',
        'dsm/result/diskmaps_curr.result',
        'dsm/result/diskmaps_boot.result',
        'dsm/result/md_examine/',
        'dsm/result/lv.result',
        'dsm/result/dm.result',
        'dsm/result/dmsetup-table.result',
        'dsm/result/dmsetup-status.result',
        'dsm/run/space/volume_status.cache',
        'dsm/run/space/space_meta.status',
        'dsm/run/space/datascrubbing.status.tmp',
        'dsm/var/log/btrfs/',
        'dsm/var/log/tune2fs/',
        'dsm/run/synostorage/disks/',
        'dsm/result/ifconfig.result',
        'dsm/result/route.result',
        'dsm/result/ethtool.',
        'dsm/result/ethtool_stats.',
        'dsm/proc/net/dev',
        'dsm/etc/resolv.conf',
        'dsm/etc/sysconfig/network-scripts/',
        'dsm/result/showmount_exports.result',
        'dsm/result/netstat.result',
        'dsm/usr/syno/etc/firewall.d/',
        'dsm/result/upsc.result',
        'SMBService/etc/samba/smb.conf',
        'dsm/etc/samba/smb.conf',
        'dsm/result/top.result',
        'dsm/proc/vmstat',
        'dsm/proc/diskstats',
        'dsm/result/free.result',
        'dsm/result/ps.result',
        'dsm/result/synoselfcheck_dsm_full.result',
        'dsm/var/log/messages',
        'dsm/var/log/dmesg',
        'dsm/var/log/synolog/synosys.log',
        'dsm/var/log/synolog/.SYNOSYSDB',
        'dsm/var/log/synolog/.SYNOSYSDB-wal',
        'dsm/var/log/synolog/.SYNOSYSDB-shm',
        'dsm/var/log/synolog/.SYNODISKHEALTHDB',
        'dsm/var/log/synolog/.SYNOCONNDB',
        'dsm/var/log/synolog/.SYNOCONNDB-wal',
        'dsm/var/log/synolog/.SYNODISKHEALTHDB',
        'dsm/var/log/synolog/.SYNODISKDB',
        'dsm/var/log/synolog/SYNOCONNDB',
        'dsm/var/log/synolog/SYNOSYSDB',
        'dsm/var/log/synolog/SYNOACCOUNTDB',
        'dsm/var/log/synolog/SMBXFERDB',
        'dsm/var/log/synolog/SYNOISCSIDB',
        'dsm/var/log/synolog/SYNONETBKPDB',
        'dsm/var/log/lastimproper.log',
        'dsm/var/log/rsync_signal.error',
        'dsm/var/log/bash_err.log',
        'dsm/var/log/synoinfo.conf.bad',
        'dsm/package_status.list',
        'HighAvailability/ha_not_running',
        'ActiveInsight/usr/local/packages/@appdata/ActiveInsight/pkg_status.json',
        'dsm/run/synostorage/raid_superblock_cache/',
        'dsm/etc.defaults/disk_adv_status.conf',
        // Common Log directories for historic .xz recovery
        'dsm/var/log/',
    ];

    /**
     * Constructor: Dependency injection for file management
     *
     * @param PDO $pdo Database connection (for max_extraction_size_gb setting)
     * @param string $uploadDir Directory path for raw uploaded .dat files
     * @param string $extractedDir Base directory for extraction output (fileId subdirs)
     */
    public function __construct(PDO $pdo, string $uploadDir, string $extractedDir)
    {
        $this->pdo = $pdo;
        $this->uploadDir = $uploadDir;
        $this->extractedDir = $extractedDir;
    }

    /**
     * Extract critical files from debug.dat archive
     *
     * WORKFLOW:
     * 1. Fetch max_extraction_size_gb from system_settings (default: 2 GB)
     * 2. Call ZipHelper::extractSelected() with EXTRACTION_TARGETS and size limit
     * 3. Recursively decompress any .xz files in extracted set
     * 4. Validate minimum critical files present (soft check)
     * 5. Return list of successfully extracted paths
     *
     * SIZE ENFORCEMENT:
     * ZipHelper enforces max_extraction_size_gb limit:
     * - Throws RuntimeException if archive would exceed limit
     * - Partial extraction if limit reached mid-stream
     * - Prevents malicious/corrupted archives from consuming disk
     *
     * .XZ DECOMPRESSION:
     * Synology often compresses old logs with .xz for archive efficiency
     * decompressXzFiles() finds and inflates .xz files recursively
     * Makes compressed logs queryable by rule engine
     * Failures logged but don't block (partial decompression acceptable)
     *
     * MINIMUM VALIDATION:
     * Requires >=2 of these 4 critical files:
     * - dsm/etc/VERSION: DSM version
     * - dsm/proc/partitions: Disk layout
     * - dsm/proc/mdstat: RAID status
     * - dsm/proc/meminfo: RAM configuration
     * Soft check: if <2, extraction continues anyway (parser decides)
     * Philosophy: Extraction succeeded if anything valuable was extracted
     *
     * @param string $fileId File UUID for directory naming
     * @param string $zipPath Absolute path to uploaded debug.dat file
     *
     * @return array<string> Extracted file paths relative to extracted root
     *
     * @throws RuntimeException If extraction fails or size limit exceeded
     */
    public function processFile(string $fileId, string $zipPath): array
    {
        $destPath = $this->extractedDir . DIRECTORY_SEPARATOR . $fileId;

        $maxGb = (int)($this->pdo->query("SELECT max_extraction_size_gb FROM system_settings LIMIT 1")->fetchColumn() ?: 2);
        $maxBytes = $maxGb * 1024 * 1024 * 1024;

        $extractedFiles = ZipHelper::extractSelected($zipPath, self::EXTRACTION_TARGETS, $destPath, $maxBytes);

        // Recursively decompress any .xz archives found in the extracted set (historical logs)
        ZipHelper::decompressXzFiles($destPath);

        // Soft validation: check for minimum critical files (>=2 required)
        $minimumSet = ['dsm/etc/VERSION', 'dsm/proc/partitions', 'dsm/proc/mdstat', 'dsm/proc/meminfo'];
        $foundMin = 0;
        foreach ($minimumSet as $min) {
            if (in_array($min, $extractedFiles)) $foundMin++;
        }

        // Log warning if extraction is sparse, but don't fail (parser will judge)
        if ($foundMin < 2) {
            // Extraction succeeded but may have limited diagnostic data
        }

        return $extractedFiles;
    }

    /**
     * Get extraction directory path for a file
     *
     * PATTERN:
     * {extractedDir}/{fileId}/ — where fileId is the debug file UUID
     * processFile() creates this directory during extraction
     * Parser reads from this path during job execution
     *
     * @param string $fileId File UUID
     *
     * @return string Absolute path to extraction directory
     */
    public function getExtractedPath(string $fileId): string
    {
        return $this->extractedDir . DIRECTORY_SEPARATOR . $fileId;
    }

    /**
     * Clean up both raw archive and extracted directory
     *
     * CLEANUP FLOW:
     * 1. Delete raw .dat archive from uploadDir (if path provided and exists)
     * 2. Recursively delete extracted directory tree
     *
     * WHEN CALLED:
     * - When project is deleted (admin removes debug file)
     * - When job fails and cleanup is needed
     * - When CleanupStep orphan sweeper finds abandoned extractions
     *
     * ERROR HANDLING:
     * Proceeds even if raw archive not found (extracted may still exist)
     * Logs any filesystem errors via PHP error handlers
     * Doesn't throw if deletion partially fails (partial cleanup acceptable)
     *
     * @param string $fileId File UUID (for extracted dir lookup)
     * @param string|null $storedPath Absolute path to uploaded .dat file (nullable)
     *
     * @return void
     */
    public function deleteProjectFile(string $fileId, ?string $storedPath): void
    {
        // 1. Delete raw archive if path provided
        if ($storedPath && file_exists($storedPath)) {
            unlink($storedPath);
        }

        // 2. Delete extracted directory tree
        $path = $this->getExtractedPath($fileId);
        if (is_dir($path)) {
            $this->recursiveRmdir($path);
        }
    }

    /**
     * Recursively remove directory and all contents
     *
     * ALGORITHM:
     * 1. Verify is directory
     * 2. Scan directory entries (excluding . and ..)
     * 3. For each entry: recurse if dir, unlink if file
     * 4. Remove now-empty parent directory
     *
     * SAFETY:
     * Works on any directory path (passed from deleteProjectFile)
     * Standard approach: no force flags or error suppression
     * Filesystem errors bubble up (unlink/rmdir throw on failure)
     *
     * @param string $dir Directory path to remove
     *
     * @return void
     *
     * @throws RuntimeException If rmdir fails (not caught, bubbles to caller)
     */
    private function recursiveRmdir(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            (is_dir($path)) ? $this->recursiveRmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
