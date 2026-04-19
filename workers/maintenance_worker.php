<?php

declare(strict_types=1);

namespace App\Workers;

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use Dotenv\Dotenv;
use PDO;

// Load Environment Variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

$pdo = Database::getConnection();
Database::setTenantContext($pdo, null, 'admin'); // Bypass RLS for maintenance

echo "AI DebugScan v3 - Maintenance Worker (Data Lifecycle Management) Started\n";
echo "======================================================================\n";

function recursiveRmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    $files = array_diff(scandir($dir), ['.', '..']);
    foreach ($files as $file) {
        $path = $dir . DIRECTORY_SEPARATOR . $file;
        (is_dir($path)) ? recursiveRmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

while (true) {
    echo "[" . date('Y-m-d H:i:s') . "] Starting maintenance cycle...\n";

    try {
        // 1. Fetch System Settings
        $stmt = $pdo->query("SELECT log_retention_days, analysis_retention_days FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        if (!$settings) {
            echo "Error: System settings not found. Retrying in 1 hour...\n";
            sleep(3600);
            continue;
        }

        $logRetention = (int)$settings['log_retention_days'];
        $analysisRetention = (int)$settings['analysis_retention_days'];

        echo "Policies: Logs={$logRetention}d, Analysis={$analysisRetention}d\n";

        // 2. Physical Log Cleanup (Purge storage/uploads)
        // Find files older than logRetention days that have storage_path set
        $logCutoff = date('Y-m-d H:i:s', strtotime("-{$logRetention} days"));
        $stmt = $pdo->prepare("SELECT id, storage_path FROM debug_files WHERE created_at < :cutoff AND storage_path IS NOT NULL");
        $stmt->execute(['cutoff' => $logCutoff]);
        $filesToPurge = $stmt->fetchAll();

        foreach ($filesToPurge as $file) {
            $fullPath = __DIR__ . '/../' . $file['storage_path'];
            if (file_exists($fullPath) && is_file($fullPath)) {
                if (unlink($fullPath)) {
                    // Update record to indicate physical log is purged but metadata remains
                    $pdo->prepare("UPDATE debug_files SET storage_path = NULL, updated_at = NOW() WHERE id = :id")
                        ->execute(['id' => $file['id']]);
                    echo "✔ Purged physical log: {$file['id']} ({$file['storage_path']})\n";
                }
            } else {
                // File missing, just null out the path
                $pdo->prepare("UPDATE debug_files SET storage_path = NULL WHERE id = :id")
                    ->execute(['id' => $file['id']]);
            }
        }

        // 3. Analysis/Reports Cleanup (Purge scan_jobs and related findings)
        $analysisCutoff = date('Y-m-d H:i:s', strtotime("-{$analysisRetention} days"));
        
        // Count before deleting for logging
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM scan_jobs WHERE created_at < :cutoff");
        $stmt->execute(['cutoff' => $analysisCutoff]);
        $analysisCount = $stmt->fetchColumn();

        if ($analysisCount > 0) {
            // Delete old jobs (scan_findings will cascade delete if ON DELETE CASCADE is set)
            $stmt = $pdo->prepare("DELETE FROM scan_jobs WHERE created_at < :cutoff");
            $stmt->execute(['cutoff' => $analysisCutoff]);
            echo "✔ Purged {$analysisCount} legacy analysis reports older than {$analysisRetention} days.\n";
        }

        // 3.5. Audit Log Cleanup
        $stmt = $pdo->prepare("DELETE FROM audit_log WHERE created_at < :cutoff");
        $stmt->execute(['cutoff' => $analysisCutoff]);
        echo "✔ Purged audit log entries older than {$analysisRetention} days.\n";

        // 4. Physical Extracted Workspace Cleanup
        // Folders in storage/extracted/ that don't have a corresponding debug_file or are older than 7 days
        $extractedDir = __DIR__ . '/../storage/extracted';
        if (is_dir($extractedDir)) {
            $folders = array_diff(scandir($extractedDir), ['.', '..']);
            foreach ($folders as $fid) {
                $folderPath = $extractedDir . '/' . $fid;
                if (!is_dir($folderPath)) continue;

                // Check if file still exists and is recent
                $stmt = $pdo->prepare("SELECT id FROM debug_files WHERE id = :id AND created_at >= :cutoff");
                $stmt->execute(['id' => $fid, 'cutoff' => $logCutoff]);
                if (!$stmt->fetch()) {
                    // Recursive delete of extracted folder using native PHP
                    recursiveRmdir($folderPath);
                    echo "✔ Purged extracted workspace: {$fid}\n";
                }
            }
        }

    } catch (\Exception $e) {
        echo "CRITICAL: Maintenance Failed: " . $e->getMessage() . "\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Cycle complete. Sleeping for 6 hours...\n";
    if (isset($argv[1]) && $argv[1] === 'once') break;
    sleep(21600); // 6 hours
}
