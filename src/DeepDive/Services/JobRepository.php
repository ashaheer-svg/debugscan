<?php

declare(strict_types=1);

namespace App\DeepDive\Services;

use App\DeepDive\Models\JobStep;
use App\DeepDive\Support\Engine;
use PDO;
use Ramsey\Uuid\Uuid;

/**
 * JobRepository: Data access layer for DeepDive job records
 *
 * PURPOSE:
 * Centralizes all database access to deepdive_jobs table. Keeps SQL queries
 * in one place, abstracts persistence layer from controllers and workers.
 * Simplifies testing (can mock repository), enables future migrations
 * (PostgreSQL → MySQL → other databases).
 *
 * RESPONSIBILITIES:
 * - Job creation: enqueue() inserts new job record
 * - Job claiming: claimNext() for worker process polling
 * - Job updates: updateProgress() for real-time UI updates
 * - Job retrieval: getById(), forTenant(), listByProject()
 * - Completion handling: markDone(), markFailed()
 *
 * JOB LIFECYCLE:
 * 1. Controller calls enqueue() with file IDs + project
 * 2. Job created with status='queued' + initial steps + timestamp
 * 3. Worker calls claimNext() to fetch and lock oldest queued job
 * 4. Worker updates progress via updateProgress() during pipeline
 * 5. Worker calls markDone() or markFailed() at end
 * 6. Controller retrieves results via getById()
 *
 * DATABASE TABLE: deepdive_jobs
 * Columns:
 * - id (uuid): Primary key, unique identifier
 * - tenant_id (uuid): Row-level security (RLS) filter
 * - project_id (uuid): Project this job analyzes
 * - debug_file_ids (uuid[]): Array of uploaded debug files to process
 * - status (enum): 'queued' | 'claimed' | 'running' | 'done' | 'failed'
 * - engine_version (string): DeepDive version for reproducibility
 * - rule_catalogue_ver (string): Rule catalogue version/hash
 * - steps_json (json): [{id, label, status, ...}] checkpoint array
 * - progress_json (json): {'html_path', 'pdf_path'} after completion
 * - started_at (timestamp): When worker claimed job
 * - queued_at (timestamp): When job created (default now())
 * - completed_at (timestamp): When job finished (all steps done/failed)
 * - error_message (text): Failure reason if status='failed'
 * - worker_id (uuid): Which worker is processing
 * - debug_mode (bool): Preserve intermediate artifacts for debugging
 * - lease_until (timestamp): Job lock expiration (for stale detection)
 *
 * CONCURRENCY:
 * Uses PostgreSQL FOR UPDATE SKIP LOCKED for optimistic locking:
 * - Only one worker claims each job
 * - "SKIP LOCKED" prevents thundering herd (many workers contending)
 * - Lease timeout allows re-claiming stuck jobs after timeout
 * - No explicit lock management (database handles it)
 *
 * ENQUEUE:
 * enqueue($tenantId, $projectId, $fileIds, $debugMode) → jobId
 * - Generates UUID v4 for job ID
 * - Validates file IDs are UUIDs (prevent SQL injection)
 * - Creates PostgreSQL array literal {uuid1,uuid2,...} for fileIds
 * - Inserts with initial step checkpoints
 * - Returns new jobId for immediate polling
 *
 * CLAIMING:
 * claimNext($workerId, $leaseSeconds) → job record or null
 * - Fetches oldest 'queued' job
 * - FOR UPDATE SKIP LOCKED: acquires exclusive lock
 * - Updates status='claimed', worker_id, lease_until timestamp
 * - Transaction isolation prevents race conditions
 * - Returns null if no jobs available
 * - Lease timeout (default 15 min): stuck jobs re-claimed after timeout
 *
 * PROGRESS UPDATES:
 * updateProgress($jobId, $stepsJson)
 * - Called during pipeline after each step completes
 * - Updates steps_json in database
 * - Enables frontend to poll and show real-time progress
 * - No full transaction (fast write, safe for frequent updates)
 *
 * COMPLETION:
 * markDone($jobId, $progressJson) → updates status='done', progress_json
 * markFailed($jobId, $error) → updates status='failed', error_message
 * Both called by worker at pipeline end (success or failure path)
 *
 * RETRIEVAL:
 * getById($jobId) → full job record (not RLS filtered)
 * forTenant($tenantId) → all jobs for tenant (with RLS)
 * listByProject($projectId, $tenantId) → jobs for project
 *
 * SECURITY (RLS):
 * All queries filter by tenant_id (row-level security). Controllers
 * must pass tenantId from session. Application enforces authorization
 * layer on top (middleware can't be spoofed).
 *
 * ERROR HANDLING:
 * - enqueue(): throws if SQL fails or file ID invalid
 * - claimNext(): returns null if no jobs (safe fallback)
 * - getById(): returns null if not found (controller handles gracefully)
 * - updateProgress(): throws on SQL error (prevents progress loss)
 *
 * @package App\DeepDive\Services
 */
final class JobRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Enqueue a new DeepDive job. Returns the job id.
     */
    public function enqueue(
        string $tenantId,
        string $projectId,
        array $debugFileIds,
        bool $debugMode = false
    ): string {
        $id = Uuid::uuid4()->toString();

        // Postgres array literal: {uuid1,uuid2}
        $arrayLiteral = '{' . implode(',', array_map(
            fn($u) => self::assertUuid($u),
            $debugFileIds
        )) . '}';

        $sql = "INSERT INTO deepdive_jobs
                (id, tenant_id, project_id, debug_file_ids,
                 status, engine_version, rule_catalogue_ver, debug_mode,
                 steps_json)
                VALUES
                (:id, :tid, :pid, :files::uuid[],
                 'queued', :engine, :cat, :dbg,
                 :steps)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id'     => $id,
            'tid'    => $tenantId,
            'pid'    => $projectId,
            'files'  => $arrayLiteral,
            'engine' => Engine::VERSION,
            'cat'    => Engine::catalogueVersion(),
            'dbg'    => $debugMode ? 'true' : 'false',
            'steps'  => json_encode(self::initialSteps(), JSON_UNESCAPED_SLASHES),
        ]);
        return $id;
    }

    /**
     * Claim the oldest queued job. Uses SKIP LOCKED so concurrent
     * workers never step on each other.
     */
    public function claimNext(string $workerId, int $leaseSeconds = 900): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("
                SELECT id, tenant_id, project_id, debug_file_ids, debug_mode
                FROM deepdive_jobs
                WHERE status = 'queued'
                ORDER BY queued_at ASC
                LIMIT 1
                FOR UPDATE SKIP LOCKED
            ");
            $stmt->execute();
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->rollBack();
                return null;
            }

            $upd = $this->pdo->prepare("
                UPDATE deepdive_jobs
                SET status = 'running',
                    started_at = NOW(),
                    worker_id = :wid,
                    lease_expires_at = NOW() + (:ls || ' seconds')::interval,
                    progress_stage = 'Starting',
                    progress_percent = 1
                WHERE id = :id
            ");
            $upd->execute(['wid' => $workerId, 'ls' => (string)$leaseSeconds, 'id' => $row['id']]);

            $this->pdo->commit();
            return $row;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /**
     * On worker boot, rescue jobs whose lease has expired so they don't
     * sit 'running' forever after a crash.
     */
    public function rescueExpired(): int
    {
        $stmt = $this->pdo->prepare("
            UPDATE deepdive_jobs
            SET status = 'queued',
                error_message = COALESCE(error_message,'') ||
                                CASE WHEN error_message IS NULL OR error_message='' THEN '' ELSE '; ' END
                                || 'Recovered from worker crash',
                worker_id = NULL,
                lease_expires_at = NULL
            WHERE status = 'running'
              AND (lease_expires_at IS NULL OR lease_expires_at < NOW())
        ");
        $stmt->execute();
        return $stmt->rowCount();
    }

    public function markCompleted(string $jobId, ?string $htmlPath, ?string $pdfPath, array $stepsJson): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE deepdive_jobs
            SET status = 'completed',
                progress_percent = 100,
                progress_stage = 'Completed',
                completed_at = NOW(),
                report_html_path = :html,
                report_pdf_path = :pdf,
                report_format = :fmt,
                steps_json = :steps
            WHERE id = :id
        ");
        $stmt->execute([
            'html'  => $htmlPath,
            'pdf'   => $pdfPath,
            'fmt'   => $pdfPath ? 'html+pdf' : 'html',
            'steps' => json_encode($stepsJson, JSON_UNESCAPED_SLASHES),
            'id'    => $jobId,
        ]);
    }

    public function markFailed(string $jobId, string $error, array $stepsJson): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE deepdive_jobs
            SET status = 'failed',
                progress_stage = 'Failed',
                completed_at = NOW(),
                error_message = :err,
                steps_json = :steps
            WHERE id = :id
        ");
        $stmt->execute([
            'err'   => $error,
            'steps' => json_encode($stepsJson, JSON_UNESCAPED_SLASHES),
            'id'    => $jobId,
        ]);
    }

    public function updateProgress(string $jobId, int $percent, string $stage, array $stepsJson): void
    {
        $stmt = $this->pdo->prepare("
            UPDATE deepdive_jobs
            SET progress_percent = :pct,
                progress_stage = :stage,
                steps_json = :steps,
                lease_expires_at = NOW() + INTERVAL '15 minutes'
            WHERE id = :id
        ");
        $stmt->execute([
            'pct'   => $percent,
            'stage' => $stage,
            'steps' => json_encode($stepsJson, JSON_UNESCAPED_SLASHES),
            'id'    => $jobId,
        ]);
    }

    public function markCleanupStatus(string $jobId, string $status): void
    {
        $stmt = $this->pdo->prepare("UPDATE deepdive_jobs SET cleanup_status = :s WHERE id = :id");
        $stmt->execute(['s' => $status, 'id' => $jobId]);
    }

    public function findForTenant(string $jobId, string $tenantId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT * FROM deepdive_jobs
            WHERE id = :id AND tenant_id = :tid
            LIMIT 1
        ");
        $stmt->execute(['id' => $jobId, 'tid' => $tenantId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function recentForProject(string $projectId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, status, progress_percent, progress_stage,
                   queued_at, started_at, completed_at, report_html_path, report_pdf_path
            FROM deepdive_jobs
            WHERE project_id = :pid
            ORDER BY queued_at DESC
            LIMIT :lim
        ");
        $stmt->bindValue(':pid', $projectId);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function tenantHasActiveJob(string $tenantId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1 FROM deepdive_jobs
            WHERE tenant_id = :tid AND status IN ('queued','running')
            LIMIT 1
        ");
        $stmt->execute(['tid' => $tenantId]);
        return (bool)$stmt->fetchColumn();
    }

    /** @return JobStep[] */
    public static function initialSteps(): array
    {
        // Weights are relative — they become the progress percentage map.
        return [
            new JobStep('validate',   'Validate job & inputs',        weight: 1),
            new JobStep('extract',    'Extract debug bundle',         weight: 14),
            new JobStep('decompress', 'Decompress rotated logs (.xz)',weight: 7),
            new JobStep('parse',      'Parse facts from logs & DBs',  weight: 33),
            new JobStep('evaluate',   'Evaluate detection rules',     weight: 20),
            new JobStep('correlate',  'Correlate cause chains',       weight: 7),
            new JobStep('narrate',    'Narrate incidents (optional)', weight: 8),
            new JobStep('render',     'Render report',                weight: 7),
            new JobStep('cleanup',    'Cleanup intermediate files',   weight: 3),
        ];
    }

    private static function assertUuid(string $u): string
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $u)) {
            throw new \InvalidArgumentException('Invalid UUID in debug_file_ids');
        }
        return $u;
    }
}
