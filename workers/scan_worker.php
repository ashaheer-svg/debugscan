<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

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
$aiService = new AiService($_ENV['GROQ_API_KEY']);

echo "AI DebugScan v3 - Scan Worker Started\n";
echo "====================================\n";

while (true) {
    // 1. Pick up a queued job using SKIP LOCKED to avoid multiple workers picking the same job
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
        sleep(2); // Wait before checking again
        continue;
    }

    // 2. Mark as running
    $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'running', started_at = NOW() WHERE id = :id");
    $stmt->execute(['id' => $job['id']]);
    $pdo->commit();

    echo "Processing Job: {$job['id']} (Project: {$job['project_id']})\n";

    try {
        // 3. Collect diagnostic data for all files in the scan
        $allDiagnosticData = [];
        foreach ($job['debug_file_ids'] as $fileId) {
             $destPath = $fileService->getExtractedPath($fileId);
             if (!is_dir($destPath)) {
                 echo "Warning: Data not extracted for $fileId. Skipping...\n";
                 continue;
             }

             // Base diagnostic data (Hardware, Disk, Raid, etc)
             $data = $parseService->parseAll($destPath);

             // Level 1: Extended Database Parsing (SQLite Logs)
             if ($job['scan_level'] === 'level1') {
                 $dbParser = new DatabaseParser($destPath);
                 $dbResults = $dbParser->parseAll();

                 // Persist extended data for future reference (Level 2 drills)
                 $stmt = $pdo->prepare("UPDATE debug_files SET extended_data = :data WHERE id = :id");
                 $stmt->execute(['id' => $fileId, 'data' => json_encode($dbResults)]);

                 // Package data for AI prompt
                 $data['packaged_logs'] = [
                     'system' => $packagingService->formatSystemEvents($dbResults['system_events'] ?? []),
                     'disk_health' => $packagingService->formatDiskHealth($dbResults['disk_health'] ?? []),
                     'connections' => $packagingService->formatConnections($dbResults['connection_logs'] ?? []),
                     'disk_ops' => $packagingService->formatDiskEvents($dbResults['disk_events'] ?? [])
                 ];
             }

             $allDiagnosticData[] = $data;
        }

        // 4. Perform AI Analysis
        $analysis = $aiService->analyze(
            $allDiagnosticData, 
            $job['ai_model'], 
            (int)$job['max_output_tokens']
        );

        // 5. Save results and mark as completed
        $stmt = $pdo->prepare("
            UPDATE scan_jobs 
            SET status = 'completed', 
                completed_at = NOW(), 
                health_score = :health, 
                result_summary = :summary,
                findings_count = :count,
                total_duration_ms = EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $job['id'],
            'health' => $analysis['health_score'] ?? 'N/A',
            'summary' => json_encode($analysis['summary'] ?? ''),
            'count' => count($analysis['findings'] ?? []),
        ]);

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
