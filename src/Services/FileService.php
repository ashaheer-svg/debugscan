<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\ZipHelper;
use PDO;
use RuntimeException;

class FileService
{
    private string $uploadDir;
    private string $extractedDir;
    private PDO $pdo;

    const EXTRACTION_TARGETS = [
        'dsm/etc/VERSION',
        'dsm/etc.defaults/VERSION',
        'dsm/proc/partitions',
        'dsm/proc/mdstat',
        'dsm/proc/meminfo',
        'dsm/proc/sys/kernel/syno_hw_version',
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
        'dsm/var/log/synolog/.SYNOCONNDB-shm',
        'dsm/var/log/synolog/.SYNODISKDB',
        'dsm/var/log/lastimproper.log',
        'dsm/var/log/rsync_signal.error',
        'dsm/var/log/bash_err.log',
        'dsm/var/log/synoinfo.conf.bad',
        'dsm/package_status.list',
        'HighAvailability/ha_not_running',
        'ActiveInsight/usr/local/packages/@appdata/ActiveInsight/pkg_status.json',
        'dsm/run/synostorage/raid_superblock_cache/',
        'dsm/etc.defaults/disk_adv_status.conf',
    ];

    public function __construct(PDO $pdo, string $uploadDir, string $extractedDir)
    {
        $this->pdo = $pdo;
        $this->uploadDir = $uploadDir;
        $this->extractedDir = $extractedDir;
    }

    /**
     * Process a single debug.dat file: extract selected files and store extraction metadata.
     */
    public function processFile(string $fileId, string $zipPath): array
    {
        $destPath = $this->extractedDir . DIRECTORY_SEPARATOR . $fileId;
        
        $extractedFiles = ZipHelper::extractSelected($zipPath, self::EXTRACTION_TARGETS, $destPath);
        
        // Mark extraction as completed if we found at least the minimum data set
        $minimumSet = ['dsm/etc/VERSION', 'dsm/proc/partitions', 'dsm/proc/mdstat', 'dsm/proc/meminfo'];
        $foundMin = 0;
        foreach ($minimumSet as $min) {
            if (in_array($min, $extractedFiles)) $foundMin++;
        }

        if ($foundMin < 2) { // Allow some flexibility
             // Extraction might be problematic, but we'll let the parser decide
        }

        return $extractedFiles;
    }

    public function getExtractedPath(string $fileId): string
    {
        return $this->extractedDir . DIRECTORY_SEPARATOR . $fileId;
    }

    /**
     * Delete both the raw upload and the extracted folder.
     */
    public function deleteProjectFile(string $fileId, ?string $storedPath): void
    {
        // 1. Delete raw archive
        if ($storedPath && file_exists($storedPath)) {
            unlink($storedPath);
        }

        // 2. Delete extracted directory
        $path = $this->getExtractedPath($fileId);
        if (is_dir($path)) {
            $this->recursiveRmdir($path);
        }
    }

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
