<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load Environment Variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

use App\Database;
use App\Services\AiService;
use App\Services\FileService;
use App\Services\ParseService;
use App\Services\PackagingService;
use App\Parsers\DatabaseParser;

$pdo = Database::getConnection();
Database::setTenantContext($pdo, null, 'admin'); // Bypass RLS for worker

$parseService = new ParseService();
$packagingService = new PackagingService();
$fileService = new FileService($pdo, __DIR__ . '/../storage/uploads', __DIR__ . '/../storage/extracted');

// Get AI credentials from environment
$aiApiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');
$aiService = new AiService($aiApiKey);

/**
 * Parses a PostgreSQL array string (e.g., "{uuid1,uuid2}") into a PHP array.
 */
function parsePgArray(?string $pgArray): array {
    if (!$pgArray || $pgArray === '{}') return [];
    return explode(',', trim($pgArray, '{}'));
}


echo "AI DebugScan v3 - Scan Worker Started\n";
echo "====================================\n";

// 0. Initial Recovery: Rescue Zombie Jobs
$stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'queued', error_message = 'Recovered from system restart/crash' WHERE status = 'running'");
$stmt->execute();
echo "Startup: Rescued any leftover zombie jobs.\n";

while (true) {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("
        SELECT id, tenant_id, project_id, scan_level, debug_file_ids, ai_model, max_input_tokens, max_output_tokens
        FROM scan_jobs
        WHERE status = 'queued'
        ORDER BY priority DESC, queued_at ASC
        LIMIT 1
        FOR UPDATE SKIP LOCKED
    ");
    $stmt->execute();
    $job = $stmt->fetch();

    if (!$job) {
        // Periodic check for stuck/timed-out jobs
        $stmtSettings = $pdo->query("SELECT auto_cancel_mins FROM system_settings LIMIT 1");
        $timeoutMins = (int)($stmtSettings->fetchColumn() ?: 30);
        
        $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'retry', error_message = 'Technical Timeout: Exceeded ' || :tm || ' minute processing limit', completed_at = NOW() WHERE status = 'running' AND updated_at < (NOW() - (:tm2 || ' minutes')::interval)");
        $stmt->execute(['tm' => $timeoutMins, 'tm2' => $timeoutMins]);
        
        $pdo->rollBack();
        if (isset($argv[1]) && $argv[1] === 'once') break;
        sleep(2);
        continue;
    }

    // 1.5 Concurrency Check
    $stmtSettings = $pdo->query("SELECT max_concurrent_scans FROM system_settings LIMIT 1");
    $maxConcurrency = (int)($stmtSettings->fetchColumn() ?: 2);
    
    $stmtRunning = $pdo->query("SELECT COUNT(*) FROM scan_jobs WHERE status = 'running'");
    $runningCount = (int)$stmtRunning->fetchColumn();
    
    if ($runningCount >= $maxConcurrency) {
        $pdo->rollBack();
        echo "Concurrency limit reached ($runningCount/$maxConcurrency). Waiting...\n";
        sleep(5);
        continue;
    }

    // 2. Mark as running
    $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'running', progress_stage = 'Initializing', progress_percent = 5, started_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $job['id']]);
    $pdo->commit();

    echo "Processing Job: {$job['id']} (Project: {$job['project_id']})\n";

    try {
        $checkpoints = [];
        $addCheckpoint = function($level, $stage, $status, $meta = []) use (&$checkpoints, $pdo, $job) {
            $checkpoints[] = [
                'level' => $level,
                'stage' => $stage,
                'status' => $status,
                'meta' => $meta,
                'ts' => date('Y-m-d H:i:s')
            ];
            $stmt = $pdo->prepare("UPDATE scan_jobs SET checkpoints = :cp, progress_stage = :stage WHERE id = :id");
            $stmt->execute(['cp' => json_encode($checkpoints, JSON_INVALID_UTF8_SUBSTITUTE), 'stage' => $stage, 'id' => $job['id']]);

        };

        // Update function for progress percent + Technical Heartbeat
        $updateProgress = function($stage, $percent = null) use ($pdo, $job) {
            if ($percent !== null) {
                $stmt = $pdo->prepare("UPDATE scan_jobs SET progress_stage = :stage, progress_percent = :percent, updated_at = NOW() WHERE id = :id");
                $stmt->execute(['stage' => $stage, 'percent' => (int)$percent, 'id' => $job['id']]);
            } else {
                // Heartbeat only update
                $stmt = $pdo->prepare("UPDATE scan_jobs SET progress_stage = :stage, updated_at = NOW() WHERE id = :id");
                $stmt->execute(['stage' => $stage, 'id' => $job['id']]);
            }
        };

        $addCheckpoint('system', 'Initializing', 'success', ['files' => count(parsePgArray($job['debug_file_ids'] ?? ''))]);

        // 3. Collect diagnostic data for all files in the scan
        $allDiagnosticData = [];
        $debugFileIds = parsePgArray($job['debug_file_ids'] ?? '');
        $fileCount = count($debugFileIds);
        foreach ($debugFileIds as $index => $fileId) {

             $updateProgress("Extracting Data (" . ($index + 1) . "/$fileCount)", 10 + (int)(($index / $fileCount) * 20));

             $destPath = __DIR__ . '/../storage/extracted' . DIRECTORY_SEPARATOR . $fileId;

             // 1. Check for cached forensic data
             $stmt = $pdo->prepare("SELECT storage_path, extended_data FROM debug_files WHERE id = :id");
             $stmt->execute(['id' => $fileId]);
             $fileRecord = $stmt->fetch();
             
             $dbResults = null;
             if ($fileRecord && $fileRecord['extended_data']) {
                 $dbResults = json_decode($fileRecord['extended_data'], true);
             }

             // 2. Perform forensic extraction if cache missing
             if (!$dbResults) {
                 $updateProgress("Forensic Extraction (" . ($index + 1) . "/$fileCount)", 30 + (int)(($index / $fileCount) * 20));
                 
                 // Fallback: Check if we need to re-extract files
                 if (!is_dir($destPath)) {
                    $zipPath = $fileRecord['storage_path'] ?? '';
                    if ($zipPath && file_exists($zipPath)) {
                        $fileService->processFile($fileId, $zipPath);
                    }
                 }

                 if (is_dir($destPath)) {
                    $dbParser = new DatabaseParser($destPath, $updateProgress);
                    $dbResults = $dbParser->parseAll();
                    
                    $addCheckpoint('forensic', 'Cache Population', 'success', [
                        'file_id' => $fileId, 
                        'stats' => $dbResults['stats'] ?? [],
                        'tables' => array_keys(array_filter($dbResults, fn($k) => $k !== 'stats', ARRAY_FILTER_USE_KEY))
                    ]);
                 }
             }

             // Base diagnostic data
             $data = $parseService->parseAll($destPath);

             // 3. Propagate hardware metadata to projects table if missing
             $hw = $data['hardware'] ?? [];
             $ver = $data['version'] ?? [];
             if (!empty($hw)) {
                $stmtProj = $pdo->prepare("
                    UPDATE projects SET
                        model = COALESCE(model, :model),
                        serial_number = COALESCE(serial_number, :serial),
                        dsm_version = COALESCE(dsm_version, :dsm),
                        ram_gb = COALESCE(ram_gb, :ram),
                        cpu_model = COALESCE(cpu_model, :cpu),
                        physical_location = COALESCE(physical_location, :loc),
                        updated_at = NOW()
                    WHERE id = :pid
                ");
                $stmtProj->execute([
                    'model' => $hw['model'] ?? null,
                    'serial' => $hw['serial'] ?? null,
                    'dsm' => $ver['product'] ?? null,
                    'ram' => $hw['ram_gb'] ?? null,
                    'cpu' => $hw['cpu_model'] ?? null,
                    'loc' => $hw['location'] ?? null,
                    'pid' => $job['project_id']
                ]);
             }

             $addCheckpoint('file', 'Technical Audit: Hardware Identity Discovery', 'success', [
                 'file_id' => $fileId, 
                 'discovery' => 'Hardware components mapped successfully',
                 'serial' => $hw['serial'] ?? 'unknown',
                 'dsm_version' => $ver['product'] ?? 'unknown'
             ]);

             // 3. Package forensic data if available (all levels)
             if ($dbResults) {
                 $package = [
                     'system' => $packagingService->formatSystemEvents($dbResults['system_events'] ?? []),
                     'disk_health' => $packagingService->formatDiskHealth($dbResults['disk_health'] ?? []),
                     'connections' => $packagingService->formatConnections($dbResults['connection_logs'] ?? []),
                     'disk_ops' => $packagingService->formatDiskEvents($dbResults['disk_events'] ?? [])
                 ];
                 $data['packaged_logs'] = $package;
             }

             $allDiagnosticData[] = $data;

             $allDiagnosticData[] = $data;
        }

        // 4. Perform AI Analysis
        $updateProgress("AI Forensic Analysis (" . ucfirst($job['scan_level']) . ")", 70);
        
        $analysis = $aiService->analyze(
            $allDiagnosticData, 
            $job['ai_model'], 
            (int)$job['max_output_tokens']
        );

        $addCheckpoint('ai', 'AI Report Generated', 'success', [
            'model' => $job['ai_model'],
            'technical_stats' => [
                'findings' => count($analysis['findings'] ?? []),
                'health_score' => $analysis['health_score'] ?? 'N/A',
                'lines_analyzed' => count($allDiagnosticData)
            ]
        ]);

        $updateProgress("Finalizing Report", 95);

        // 5. Save results and mark as completed
        $stmt = $pdo->prepare("
            UPDATE scan_jobs 
            SET status = 'completed', 
                progress_stage = 'Completed',
                progress_percent = 100,
                completed_at = NOW(), 
                health_score = :health, 
                result_summary = :summary,
                result_input_payload = :payload,
                findings_count = :count,
                checkpoints = :cp,
                total_duration_ms = EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000
            WHERE id = :id

        ");

        $stmt->execute([
            'id' => $job['id'],
            'health' => $analysis['health_score'] ?? 'N/A',
            'summary' => json_encode($analysis['summary'] ?? '', JSON_INVALID_UTF8_SUBSTITUTE),
            'payload' => json_encode($allDiagnosticData, JSON_INVALID_UTF8_SUBSTITUTE),
            'count' => count($analysis['findings'] ?? []),
            'cp' => json_encode($checkpoints, JSON_INVALID_UTF8_SUBSTITUTE)
        ]);


        
        $addCheckpoint('system', 'Workflow Finished', 'success');

        // 6. Save individual findings
        if (isset($analysis['findings'])) {
            foreach ($analysis['findings'] as $finding) {
                $stmt = $pdo->prepare("
                    INSERT INTO scan_findings (scan_job_id, tenant_id, category, severity, title, description, recommendation, evidence)
                    VALUES (:job_id, :tenant_id, :category, :severity, :title, :description, :recommendation, :evidence)
                ");
                $stmt->execute([
                    'job_id' => $job['id'],
                    'tenant_id' => $job['tenant_id'],
                    'category' => $finding['category'] ?? 'General',
                    'severity' => $finding['severity'] ?? 'info',
                    'title' => $finding['title'] ?? 'N/A',
                    'description' => $finding['description'] ?? 'N/A',
                    'recommendation' => $finding['recommendation'] ?? 'N/A',
                    'evidence' => json_encode($finding['evidence'] ?? []),
                ]);
            }
        }

        echo "Job Completed: {$job['id']}\n";

    } catch (\Throwable $e) {
        // Catch ANY error (ArgumentCount, Type, etc) to prevent worker death
        $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'failed', error_message = :err, completed_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $job['id'], 'err' => "Fatal Error: " . $e->getMessage()]);
        echo "Job Fatal Error: {$job['id']} - {$e->getMessage()}\n";
    }
}
