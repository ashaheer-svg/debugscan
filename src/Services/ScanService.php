<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

class ScanService
{
    private PDO $pdo;
    private ReportPlanService $reportPlanService;

    public function __construct(PDO $pdo, ReportPlanService $reportPlanService)
    {
        $this->pdo = $pdo;
        $this->reportPlanService = $reportPlanService;
    }

    /**
     * Queue a new scan job.
     */
    public function queueScan(string $tenantId, string $projectId, array $fileIds, string $reportPlanId): string
    {
        // 0. Fetch Plan & Check Authorization
        $plan = $this->reportPlanService->getPlan($reportPlanId);
        if (!$plan) {
            throw new RuntimeException("Report plan not found: $reportPlanId");
        }

        if (!$this->reportPlanService->isPlanAuthorized($tenantId, $reportPlanId)) {
            throw new RuntimeException("FORBIDDEN: Tenant is not authorized for package: " . $plan['name']);
        }

        // 1. Check Token Credits
        $stmt = $this->pdo->prepare("SELECT tokens_available FROM users WHERE id = :tid");
        $stmt->execute(['tid' => $tenantId]);
        $user = $stmt->fetch();
        
        if (!$user) {
            throw new RuntimeException("Tenant user not found: $tenantId");
        }

        $requiredTokens = (int)$plan['token_charge'];
        if ($user['tokens_available'] < $requiredTokens) {
            throw new RuntimeException("INSUFFICIENT_TOKENS:" . $user['tokens_available'] . ":" . $requiredTokens);
        }

        // 2. Insert the job with initial "Confirmed" checkpoint
        $initialCP = json_encode([[
            'level' => 'system',
            'stage' => 'Job Confirmed',
            'status' => 'success',
            'meta' => [
                'confirmed_at' => date('Y-m-d H:i:s'),
                'package' => $plan['name']
            ],
            'ts' => date('Y-m-d H:i:s')
        ]]);

        $stmt = $this->pdo->prepare("
            INSERT INTO scan_jobs (
                tenant_id, project_id, report_plan_id, debug_file_ids, 
                ai_model, max_input_tokens, max_output_tokens, status, checkpoints
            )
            VALUES (
                :tenant_id, :project_id, :report_plan_id, :debug_file_ids, 
                :ai_model, :max_input, :max_output, 'queued', :cp
            )
            RETURNING id
        ");

        // Convert array to Postgres array format with UUID validation
        foreach ($fileIds as $fid) {
            if (!\Ramsey\Uuid\Uuid::isValid($fid)) {
                throw new RuntimeException("Invalid File ID format: " . $fid);
            }
        }
        $pgArray = '{' . implode(',', $fileIds) . '}';

        $stmt->execute([
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'report_plan_id' => $plan['id'],
            'debug_file_ids' => $pgArray,
            'ai_model' => $plan['ai_model'],
            'max_input' => $plan['max_input_tokens'],
            'max_output' => $plan['max_output_tokens'],
            'cp' => $initialCP
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
