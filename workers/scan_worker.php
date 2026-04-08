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
$parseService = new ParseService();
$packagingService = new PackagingService();
$fileService = new FileService($pdo, __DIR__ . '/../storage/uploads', __DIR__ . '/../storage/extracted');

// Get AI credentials from environment
$aiApiKey = getenv('GROQ_API_KEY') ?: ($_ENV['GROQ_API_KEY'] ?? '');
$aiService = new AiService($aiApiKey);

echo "AI DebugScan v3 - Scan Worker Started\n";
echo "====================================\n";
echo "DEBUG: DB_NAME ENV: " . getenv('DB_NAME') . "\n";
echo "DEBUG: DB CONNECTED: " . $pdo->query("SELECT current_database()")->fetchColumn() . "\n";
echo "DEBUG: DB SEARCH PATH: " . $pdo->query("SELECT current_setting('search_path')")->fetchColumn() . "\n";
echo "DEBUG: DB USER: " . $pdo->query("SELECT current_user")->fetchColumn() . "\n";
echo "DEBUG: GROQ_KEY: " . substr($aiApiKey, 0, 8) . "...\n";

while (true) {
    // 1. Pick up a queued job
    $allTables = $pdo->query("SELECT schemaname, tablename FROM pg_catalog.pg_tables ORDER BY schemaname, tablename")->fetchAll(PDO::FETCH_ASSOC);
    echo "DEBUG: Available Tables:\n";
    foreach ($allTables as $t) {
        if ($t['schemaname'] !== 'pg_catalog' && $t['schemaname'] !== 'information_schema') {
            echo "  - {$t['schemaname']}.{$t['tablename']}\n";
        }
    }

    $stmt = $pdo->prepare("
        SELECT id, status
        FROM scan_jobs
        LIMIT 1
    ");
    $stmt->execute();
    $test = $stmt->fetch();
    echo "DEBUG: Simple scan_jobs count test: " . ($test ? 'found one' : 'zero rows found') . "\n";

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
        $pdo->rollBack();
        echo "DEBUG: No queued jobs found.\n";
        if (isset($argv[1]) && $argv[1] === 'once') break;
        sleep(2);
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
            $stmt->execute(['cp' => json_encode($checkpoints), 'stage' => $stage, 'id' => $job['id']]);
        };

        // Update function for progress percent
        $updateProgress = function($stage, $percent) use ($pdo, $job) {
            $stmt = $pdo->prepare("UPDATE scan_jobs SET progress_stage = :stage, progress_percent = :percent WHERE id = :id");
            $stmt->execute(['stage' => $stage, 'percent' => $percent, 'id' => $job['id']]);
        };

        $addCheckpoint('system', 'Initializing', 'success', ['files' => count($job['debug_file_ids'])]);

        // 3. Collect diagnostic data for all files in the scan
        $allDiagnosticData = [];
        $fileCount = count($job['debug_file_ids']);
        foreach ($job['debug_file_ids'] as $index => $fileId) {
             $updateProgress("Extracting Data (" . ($index + 1) . "/$fileCount)", 10 + (int)(($index / $fileCount) * 20));

             $destPath = $fileService->getExtractedPath($fileId);
             if (!is_dir($destPath)) {
                 $addCheckpoint('file', 'File Extraction', 'failed', ['file_id' => $fileId, 'error' => 'Extraction directory missing']);
                 continue;
             }
             $addCheckpoint('file', 'File Extraction', 'success', ['file_id' => $fileId, 'path' => $destPath]);

             // Base diagnostic data
             $data = $parseService->parseAll($destPath);
             $addCheckpoint('file', 'Base Parsing', 'success', ['file_id' => $fileId, 'dsm' => $data['version']['product'] ?? 'unknown']);

             // Level 1: Extended Database Parsing (SQLite Logs)
             if ($job['scan_level'] === 'level1') {
                 $updateProgress("Forensic Database Extraction (" . ($index + 1) . "/$fileCount)", 30 + (int)(($index / $fileCount) * 30));
                 $dbParser = new DatabaseParser($destPath);
                 $dbResults = $dbParser->parseAll();

                 $rowCount = array_sum(array_map('count', $dbResults));
                 $addCheckpoint('forensic', 'SQLite Extraction', 'success', [
                     'file_id' => $fileId, 
                     'rows_total' => $rowCount,
                     'tables' => array_keys($dbResults)
                 ]);

                 // Persist extended data for future reference (Level 2 drills)
                 $stmt = $pdo->prepare("UPDATE debug_files SET extended_data = :data WHERE id = :id");
                 $stmt->execute(['id' => $fileId, 'data' => json_encode($dbResults)]);

                 // Package data for AI prompt
                 $package = [
                     'system' => $packagingService->formatSystemEvents($dbResults['system_events'] ?? []),
                     'disk_health' => $packagingService->formatDiskHealth($dbResults['disk_health'] ?? []),
                     'connections' => $packagingService->formatConnections($dbResults['connection_logs'] ?? []),
                     'disk_ops' => $packagingService->formatDiskEvents($dbResults['disk_events'] ?? [])
                 ];
                 $data['packaged_logs'] = $package;

                 $addCheckpoint('forensic', 'Data Packaged', 'success', [
                     'file_id' => $fileId,
                     'package_size_bytes' => strlen(json_encode($package))
                 ]);
             }

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
            'findings_count' => count($analysis['findings'] ?? []),
            'health_score' => $analysis['health_score'] ?? 'N/A'
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
                findings_count = :count,
                checkpoints = :cp,
                total_duration_ms = EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $job['id'],
            'health' => $analysis['health_score'] ?? 'N/A',
            'summary' => json_encode($analysis['summary'] ?? ''),
            'count' => count($analysis['findings'] ?? []),
            'cp' => json_encode($checkpoints)
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

    } catch (\Exception $e) {
        $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'failed', error_message = :err, completed_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $job['id'], 'err' => $e->getMessage()]);
        echo "Job Failed: {$job['id']} - {$e->getMessage()}\n";
    }
}
