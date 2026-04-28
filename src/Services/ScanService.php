<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * ScanService: Orchestrate analysis job lifecycle
 *
 * PURPOSE:
 * Queue and manage analysis jobs (scans) for tenants
 * Validates preconditions: token balance, plan authorization, file access
 * Tracks job progress through pipeline execution
 *
 * JOB LIFECYCLE:
 * 1. queueScan(): Validate and queue new job
 * 2. getJobStatus(): Monitor execution progress
 * 3. Check plan authorization and tenant token balance
 * 4. Return job ID for frontend polling
 *
 * SECURITY:
 * - Tenant isolation: Filter by tenant_id
 * - Plan authorization: Check tenant has access to plan
 * - Token validation: Verify sufficient tokens before queueing
 * - File validation: UUID format validation
 *
 * CHECKPOINT SYSTEM:
 * Jobs track progress via checkpoint array:
 * - JSON array of execution milestones
 * - Each checkpoint: stage, status, timestamp, metadata
 * - Used for real-time progress display
 *
 * ERROR HANDLING:
 * - Invalid plan: RuntimeException
 * - Unauthorized: RuntimeException with FORBIDDEN
 * - Insufficient tokens: RuntimeException with INSUFFICIENT_TOKENS:current:required
 * - Invalid file ID: UUID format validation
 *
 * @package App\Services
 */
class ScanService
{
    /** @var PDO Database connection for job storage */
    private PDO $pdo;

    /** @var ReportPlanService Report template and plan access */
    private ReportPlanService $reportPlanService;

    /**
     * Constructor: Dependency injection
     *
     * @param PDO $pdo Database connection
     * @param ReportPlanService $reportPlanService Plan manager
     */
    public function __construct(PDO $pdo, ReportPlanService $reportPlanService)
    {
        $this->pdo = $pdo;
        $this->reportPlanService = $reportPlanService;
    }

    /**
     * Queue new analysis job with validation
     *
     * FLOW:
     * 1. Fetch and validate report plan
     * 2. Check tenant authorization for plan
     * 3. Verify token balance >= required tokens
     * 4. Validate file IDs (UUID format)
     * 5. Create scan_jobs record with initial checkpoint
     * 6. Return job ID
     *
     * PRECONDITIONS:
     * - Plan exists and is authorized for tenant
     * - Tenant has sufficient tokens
     * - All file IDs are valid UUIDs
     *
     * CHECKPOINT:
     * Initial checkpoint records job confirmation
     * Used for job status tracking and progress display
     *
     * @param string $tenantId Tenant organization ID
     * @param string $projectId Project within tenant
     * @param array $fileIds Debug file IDs to analyze (UUID format)
     * @param string $reportPlanId Report template ID
     *
     * @return string New job ID (UUID)
     *
     * @throws RuntimeException On validation failure
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
     * Retrieve current status and progress metrics for a scan job
     *
     * FLOW:
     * 1. Query scan_jobs table by job ID
     * 2. Optionally filter by tenant_id (access control)
     * 3. Return job record with status and progress fields
     *
     * STATUS FIELDS:
     * - id: Job UUID
     * - status: Job state (queued|processing|completed|failed)
     * - health_score: System health metric (0-100)
     * - findings_count: Count of rule matches found
     * - error_message: Error details if failed (nullable)
     * - progress_percent: Completion percentage (0-100)
     * - progress_stage: Current pipeline stage name (validate, parse, evaluate, etc.)
     *
     * TENANT ISOLATION:
     * Optional tenantId parameter adds WHERE clause
     * Prevents cross-tenant access (404s or access denied if ID not owned by tenant)
     * If tenantId omitted, any job ID returns data (use only for internal queries)
     *
     * FRONTEND POLLING:
     * Designed for repeated frontend status checks during job execution
     * Lightweight query returns minimal fields for UI refresh
     * Supports ETag or polling interval optimization
     *
     * @param string $jobId Job UUID to look up
     * @param string|null $tenantId Optional tenant owner (for access control)
     *
     * @return array Job record: {id, status, health_score, findings_count, error_message, progress_percent, progress_stage}
     *
     * @throws RuntimeException If job not found or tenant doesn't own job
     */
    public function getJobStatus(string $jobId, ?string $tenantId = null): array
    {
        $sql = "SELECT id, status, health_score, findings_count, error_message, progress_percent, progress_stage FROM scan_jobs WHERE id = :id";
        $params = ['id' => $jobId];

        if ($tenantId) {
            $sql .= " AND tenant_id = :tid";
            $params['tid'] = $tenantId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $job = $stmt->fetch();
        
        if (!$job) {
            throw new RuntimeException("Scan job not found or access denied: $jobId");
        }

        return $job;
    }
}
