<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class ScanService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Queue a new scan job.
     */
    public function queueScan(string $tenantId, string $projectId, array $fileIds, string $level, string $model): string
    {
        // 1. Get default token limits for the level
        $stmt = $this->pdo->query("SELECT level1_max_input_tokens, level1_max_output_tokens, level2_max_input_tokens, level2_max_output_tokens FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        $maxInput = ($level === 'level1') ? $settings['level1_max_input_tokens'] : $settings['level2_max_input_tokens'];
        $maxOutput = ($level === 'level1') ? $settings['level1_max_output_tokens'] : $settings['level2_max_output_tokens'];

        // 2. Insert the job
        $stmt = $this->pdo->prepare("
            INSERT INTO scan_jobs (tenant_id, project_id, scan_level, debug_file_ids, ai_model, max_input_tokens, max_output_tokens, status)
            VALUES (:tenant_id, :project_id, :scan_level, :debug_file_ids, :ai_model, :max_input, :max_output, 'queued')
            RETURNING id
        ");

        // Convert array to Postgres array format
        $pgArray = '{' . implode(',', $fileIds) . '}';

        $stmt->execute([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'scan_level' => $level,
            'debug_file_ids' => $pgArray,
            'ai_model' => $model,
            'max_input' => $maxInput,
            'max_output' => $maxOutput,
        ]);

        $result = $stmt->fetch();
        return $result['id'];
    }

    /**
     * Get the current status and progress of a scan job.
     */
    public function getJobStatus(string $jobId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, status, health_score, findings_count, error_message, progress_percent, progress_stage FROM scan_jobs WHERE id = :id");
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();
        
        if (!$job) {
            throw new RuntimeException("Scan job not found: $jobId");
        }

        return $job;
    }
}
