<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

// Load Environment Variables
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

// Ensure storage/logs exists for startup tracking
$logDir = __DIR__ . '/../storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@file_put_contents($logDir . '/worker_startup.log', "[" . date('Y-m-d H:i:s') . "] Worker process started (v3.1 - Enhanced AI Trace)\n", FILE_APPEND);

echo "AI DebugScan Worker v3.1 Started...\n";

use App\Database;
use App\Services\AiService;
use App\Services\FileService;
use App\Services\ParseService;
use App\Services\PackagingService;
use App\Services\ExtractionConfigService;
use App\Services\ReportPlanService;
use App\Parsers\DatabaseParser;

$pdo = Database::getConnection();
Database::setTenantContext($pdo, null, 'admin'); // Bypass RLS for worker

$parseService     = new ParseService();
$packagingService = new PackagingService();
$fileService      = new FileService($pdo, __DIR__ . '/../storage/uploads', __DIR__ . '/../storage/extracted');
$extractionConfig = new ExtractionConfigService($pdo);
$reportPlanService = new ReportPlanService($pdo);

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
        SELECT id, tenant_id, project_id, report_plan_id, debug_file_ids, ai_model, max_input_tokens, max_output_tokens
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

    // 1.5 Load Report Plan
    $planId = $job['report_plan_id'];
    
    if (!$planId) {
         $pdo->prepare("UPDATE scan_jobs SET status = 'failed', error_message = 'Legacy Error: Job missing Report Plan ID', completed_at = NOW() WHERE id = :id")->execute(['id' => $job['id']]);
         $pdo->commit();
         continue;
    }
    
    $plan = $reportPlanService->getPlan($planId);
    if (!$plan) {
         $pdo->prepare("UPDATE scan_jobs SET status = 'failed', error_message = 'Report Plan not found', completed_at = NOW() WHERE id = :id")->execute(['id' => $job['id']]);
         $pdo->commit();
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
    $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'running', progress_stage = 'Initializing', progress_percent = 5, started_at = NOW(), report_plan_id = :pid WHERE id = :id");
    $stmt->execute(['id' => $job['id'], 'pid' => $planId]);
    $pdo->commit();

    echo "Processing Job: {$job['id']} (Plan: {$plan['name']})\n";

    try {
        // Safe initialization: try to recover existing checkpoints or start fresh
        $existingCP = $job['checkpoints'] ?? '[]';
        $checkpoints = [];
        try {
            $checkpoints = is_string($existingCP) ? json_decode($existingCP, true) : ($existingCP ?: []);
            if (!is_array($checkpoints)) $checkpoints = [];
        } catch (\Throwable $e) {
            $checkpoints = [];
        }
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

        $addCheckpoint('system', 'Worker Active', 'success', ['pid' => getmypid(), 'plan' => $plan['name']]);

        // Load extraction config for this scan's level (L1 and L2 both have configurable sections now)
        $activeConfig  = $extractionConfig->getRuntimeConfig($planId);

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

                     // Use active config for this plan
                     $dbParser  = new DatabaseParser($destPath, $updateProgress, $activeConfig);
                     $dbResults = $dbParser->parseAll();
                    
                    $rowCount = 0;
                    if (isset($dbResults['stats'])) {
                        foreach ($dbResults['stats'] as $table => $count) {
                            $rowCount += (int)$count;
                        }
                    }

                    $addCheckpoint('forensic', 'Cache Population', 'success', [
                        'file_id' => $fileId, 
                        'capacity' => "$rowCount forensic records extracted",
                        'stats' => $dbResults['stats'] ?? [],
                        'tables' => array_keys(array_filter($dbResults, fn($k) => $k !== 'stats', ARRAY_FILTER_USE_KEY))
                    ]);
                 }

             // Base diagnostic data (pass active config)
             $data = $parseService->parseAll($destPath, $activeConfig ?? []);

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
                 'capacity' => !empty($hw) ? 'Hardware metadata identified' : 'No hardware metadata found',
                 'data_link' => "/files/report/$fileId",
                 'serial' => (string)($hw['serial'] ?? 'unknown'),
                 'dsm_version' => (string)($ver['product'] ?? 'unknown'),
                 'model' => (string)($hw['model'] ?? 'Unknown Synology')
             ]);

             // 3. Package raw forensic data if available (all levels)
             if ($dbResults) {
                 $data['forensic_extractions'] = $dbResults;
             }

             $allDiagnosticData[] = $data;
        }

        // 4. Perform AI Analysis
        $updateProgress("AI Forensic Analysis (" . $plan['name'] . ")", 70);
        
        $stmtSettings = $pdo->query("SELECT max_prompt_chars FROM system_settings LIMIT 1");
        $maxChars = (int)($stmtSettings->fetchColumn() ?: 50000);
        
        $totalInputChars = strlen(json_encode($allDiagnosticData));

        $addCheckpoint('ai', 'AI Analysis Started', 'success', [
            'data_set_count' => count($allDiagnosticData),
            'model' => $job['ai_model'] ?? $plan['ai_model'],
            'capacity' => number_format($totalInputChars) . ' characters in raw payload',
            'data_link' => "/admin/scans/raw/{$job['id']}",
            'char_limit' => $maxChars
        ]);

        $analysis = $aiService->analyze(
            $allDiagnosticData, 
            $job['ai_model'] ?? $plan['ai_model'], 
            (int)($job['max_output_tokens'] ?? $plan['max_output_tokens']),
            $maxChars,
            (string)$job['id'],
            $plan['prompt_header']
        );

        $findings = $analysis['findings'] ?? [];
        $actualPrompt = $analysis['full_prompt'] ?? null;
        $isTruncated = $analysis['is_truncated'] ?? false;

        $addCheckpoint('ai', 'AI Report Generated', 'success', [
            'model' => $job['ai_model'],
            'data_link' => "/scans/report/{$job['id']}",
            'capacity' => count($findings) . " forensic findings generated",
            'payload_stored' => $actualPrompt ? 'Full Prompt' : 'Raw Data Only',
            'technical_stats' => [
                'findings' => count($findings),
                'health_score' => $findings['health_score'] ?? 'N/A',
                'lines_analyzed' => count($allDiagnosticData),
                'truncated' => $isTruncated
            ]
        ]);

        $updateProgress("Finalizing Report", 95);

        // 5. Save results and mark as completed
        $usage = $analysis['usage'] ?? [];
        $stmt = $pdo->prepare("
            UPDATE scan_jobs 
            SET status = 'completed', 
                progress_stage = 'Completed',
                progress_percent = 100,
                completed_at = NOW(), 
                health_score = :health, 
                result_summary = :summary,
                result_input_payload = :payload,
                is_truncated = :truncated,
                findings_count = :count,
                ai_input_tokens_used = :in_tokens,
                ai_output_tokens_used = :out_tokens,
                checkpoints = :cp,
                total_duration_ms = EXTRACT(EPOCH FROM (NOW() - started_at)) * 1000
            WHERE id = :id
        ");
        
        $stmt->execute([
            'health' => $findings['health_score'] ?? 'N/A',
            'summary' => json_encode($findings['summary'] ?? '', JSON_INVALID_UTF8_SUBSTITUTE),
            'payload' => json_encode(['raw' => $actualPrompt], JSON_INVALID_UTF8_SUBSTITUTE) ?? json_encode($allDiagnosticData),
            'truncated' => $isTruncated ? 1 : 0,
            'count' => count($findings['findings'] ?? []),
            'in_tokens' => (int)($usage['prompt_tokens'] ?? 0),
            'out_tokens' => (int)($usage['completion_tokens'] ?? 0),
            'cp' => json_encode($checkpoints, JSON_INVALID_UTF8_SUBSTITUTE),
            'id' => $job['id']
        ]);

        $addCheckpoint('system', 'Workflow Finished', 'success');

        // 6. Save individual findings
        if (isset($findings['findings'])) {
            foreach ($findings['findings'] as $finding) {
                $stmt = $pdo->prepare("
                    INSERT INTO scan_findings (scan_job_id, tenant_id, category, severity, title, description, recommendation, evidence)
                    VALUES (:job_id, :tenant_id, :category, :severity, :title, :description, :recommendation, :evidence)
                ");
                $stmt->execute([
                    'job_id' => $job['id'],
                    'tenant_id' => $job['tenant_id'],
                    'category' => (string)($finding['category'] ?? 'General'),
                    'severity' => $finding['severity'] ?? 'info',
                    'title' => $finding['title'] ?? 'N/A',
                    'description' => $finding['description'] ?? 'N/A',
                    'recommendation' => $finding['recommendation'] ?? 'N/A',
                    'evidence' => json_encode($finding['evidence'] ?? []),
                ]);
            }
        }

        // 7. Update User tokens and counters
        $inTokens = (int)($usage['prompt_tokens'] ?? 0);
        $outTokens = (int)($usage['completion_tokens'] ?? 0);
        $totalTokens = $inTokens + $outTokens;
        
        // Final token cost can be base plan cost OR actual usage
        // For now, let's stick to actual tokens as requested previously, but use the plan ID for counters
        $isL2 = ($planId === '22222222-2222-4222-a222-222222222222');
        
        // Fetch balance before
        $stmt = $pdo->prepare("SELECT tokens_available FROM users WHERE id = :tid");
        $stmt->execute(['tid' => $job['tenant_id']]);
        $beforeBalance = (int)($stmt->fetchColumn() ?: 0);
        $afterBalance = max(0, $beforeBalance - $totalTokens);

        $stmt = $pdo->prepare("
            UPDATE users 
            SET tokens_available = :after,
                tokens_used = tokens_used + :used,
                scans_level1_count = scans_level1_count + :l1_inc,
                scans_level2_count = scans_level2_count + :l2_inc
            WHERE id = :tenant_id
        ");
        $stmt->execute([
            'after' => $afterBalance,
            'used' => $totalTokens,
            'l1_inc' => $isL2 ? 0 : 1,
            'l2_inc' => $isL2 ? 1 : 0,
            'tenant_id' => $job['tenant_id']
        ]);

        // Update Job with tokens charged
        $stmt = $pdo->prepare("UPDATE scan_jobs SET tokens_charged = :used WHERE id = :id");
        $stmt->execute(['used' => $totalTokens, 'id' => $job['id']]);

        // 8. Audit log entry for token usage
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (tenant_id, action, details)
            VALUES (:tenant_id, 'tokens_deducted', :details)
        ");
        $stmt->execute([
            'tenant_id' => $job['tenant_id'],
            'details' => json_encode([
                'job_id' => $job['id'],
                'amount' => $totalTokens,
                'used' => $totalTokens,
                'before' => $beforeBalance,
                'after' => $afterBalance,
                'scan_plan' => $plan['name']
            ])
        ]);

        echo "Job Completed: {$job['id']} | Tokens Used: $totalTokens\n";

    } catch (\Throwable $e) {
        $addCheckpoint('system', 'Fatal Error', 'failed', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]);

        // Catch ANY error (ArgumentCount, Type, etc) to prevent worker death
        $stmt = $pdo->prepare("UPDATE scan_jobs SET status = 'failed', error_message = :err, completed_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $job['id'], 'err' => "Fatal Error: " . $e->getMessage()]);
        echo "Job Fatal Error: {$job['id']} - {$e->getMessage()}\n";
    }
}
