<?php

declare(strict_types=1);

namespace App\DeepDive\Worker;

use App\DeepDive\Logging\LogExporter;
use App\DeepDive\Logging\PipelineLogger;
use App\DeepDive\Pipeline\CleanupStep;
use App\DeepDive\Pipeline\CorrelateStep;
use App\DeepDive\Pipeline\DecompressStep;
use App\DeepDive\Pipeline\EvaluateStep;
use App\DeepDive\Pipeline\ExtractStep;
use App\DeepDive\Pipeline\NarrateStep;
use App\DeepDive\Pipeline\ParseStep;
use App\DeepDive\Pipeline\Pipeline;
use App\DeepDive\Pipeline\PipelineContext;
use App\DeepDive\Pipeline\RenderStep;
use App\DeepDive\Pipeline\ValidateStep;
use App\DeepDive\Services\JobRepository;
use App\DeepDive\Support\Paths;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Daemon: Long-running background worker for DeepDive analysis jobs
 *
 * PURPOSE:
 * Infinite-loop daemon process that continuously polls deepdive_jobs for
 * queued analysis jobs. Claims jobs, runs complete pipeline (validate →
 * decompress → parse → evaluate → correlate → narrate → render → cleanup),
 * updates progress in real-time, persists results to database.
 * Completely isolated from existing ScanService worker (different process,
 * different queue, different storage).
 *
 * EXECUTION MODEL:
 * 1. Start daemon (typically via supervisor or cron)
 * 2. Enter infinite loop: poll for jobs, sleep when idle
 * 3. JobRepository::claimNext() atomically locks oldest queued job
 * 4. Run entire Pipeline on claimed job (see Pipeline class)
 * 5. Update job status and progress throughout pipeline
 * 6. Repeat until restart conditions (memory, job count) met
 *
 * STARTUP:
 * - Log worker ID (hostname:pid)
 * - Call rescueOnBoot() to claim stuck jobs from previous crashes
 * - Begin main loop
 *
 * POLLING LOOP:
 * run(once=false): Infinite loop
 * - claimNext(workerId, leaseSeconds): Attempts to lock job
 * - Returns null if no jobs available
 * - On null: sleep(idleSleepSeconds=3) and retry
 * - On job: call processJob() to run full pipeline
 * - Check restart conditions (memory, job count)
 * - If restart needed: return (supervisor restarts process)
 *
 * ONCE MODE:
 * run(once=true): Process at most one job then exit
 * Used for testing, cron-based execution (alternative to daemon).
 * - Claim one job
 * - If none: log and exit immediately
 * - If one: process it and exit (no restart conditions checked)
 *
 * JOB PROCESSING:
 * processJob(row): Execute complete pipeline on single job
 * - Create PipelineContext from job record
 * - Instantiate all 8 pipeline steps
 * - Create Pipeline orchestrator
 * - Pipeline.run() executes steps sequentially
 * - On success: markDone() updates status and progress
 * - On exception: markFailed() logs error, updates status
 *
 * PIPELINE STEPS:
 * 1. ValidateStep: Check file integrity, format
 * 2. DecompressStep: Decompress .xz files
 * 3. ExtractStep: Unzip bundles with security checks
 * 4. ParseStep: Extract data via BundleLocator + parsers
 * 5. EvaluateStep: Run all rules against parsed data
 * 6. CorrelateStep: Group findings into incidents (union-find)
 * 7. NarrateStep: AI-generate textual analysis (optional, Groq)
 * 8. RenderStep: Generate HTML report
 * 9. CleanupStep: Remove artifacts, finalize
 *
 * RESTART CONDITIONS:
 * After each job completion, check:
 * - processed >= restartAfterJobs (default: 25)
 * - memory_get_usage >= restartAfterMemoryMb (default: 256)
 * If any condition met: return (supervisor/parent handles restart)
 * Benefits:
 * - Prevents memory leaks from accumulating over long runs
 * - Allows fresh PHP execution state
 * - Supervisor can rotate process safely
 *
 * MEMORY MANAGEMENT:
 * Each job extracts bundles (potentially large), processes, then cleans up.
 * PHP garbage collection may not release memory back to OS immediately.
 * Restart threshold (256MB) ensures process doesn't bloat indefinitely.
 * Supervisor (e.g. systemd, supervisord) restarts daemon automatically.
 *
 * WORKER ID:
 * workerId = hostname:pid (e.g. "deepdive-01:12345")
 * Used for:
 * - Job claim tracking (database lease_until records which worker)
 * - Logging context (identify which worker processed which job)
 * - Timeout detection (if worker dies, lease expires after timeout)
 *
 * STALE JOB RECOVERY:
 * rescueOnBoot(): On daemon start, look for jobs with expired leases
 * - Worker crashed without cleaning up (network failure, OOM kill, segfault)
 * - Job stuck in 'claimed' status with old worker_id
 * - Lease timeout (e.g. 15 min) has elapsed
 * - rescueOnBoot() marks these 'queued' again (back to polling)
 * - Prevents permanent job loss on worker crash
 *
 * ERROR HANDLING:
 * Pipeline exceptions caught by processJob():
 * - Pipeline throws: not all steps return gracefully
 * - Catch: call markFailed() with exception message
 * - Log: full stack trace to worker logs
 * - Continue: move to next job (exception doesn't crash daemon)
 * - Job status: 'failed', error_message has details
 *
 * ISOLATION FROM SCAN WORKER:
 * - Different process (can't conflict for resources)
 * - Different queue table (deepdive_jobs vs scans)
 * - Different storage (storage/deepdive vs storage/extracted)
 * - Crash in DeepDive worker cannot affect scans
 *
 * INTEGRATION WITH CONTROLLER:
 * - Controller calls JobRepository::enqueue() to create job
 * - Controller polls JobRepository::progress() for frontend updates
 * - Daemon processes job independently
 * - Both use same JobRepository for safe concurrent access
 *
 * TYPICAL DEPLOYMENT:
 * supervisord config:
 * [program:deepdive-worker]
 * command = php /app/workers/deepdive_worker.php
 * autostart = true
 * autorestart = true
 * numprocs = 2
 * stdout_logfile = /var/log/deepdive-worker.log
 * stderr_logfile = /var/log/deepdive-worker.log
 *
 * @package App\DeepDive\Worker
 */
final class Daemon
{
    private string $workerId;

    public function __construct(
        private readonly PDO             $pdo,
        private readonly JobRepository   $jobs,
        private readonly LoggerInterface $logger,
        private readonly int             $idleSleepSeconds = 3,
        private readonly int             $restartAfterJobs = 25,
        private readonly int             $restartAfterMemoryMb = 256,
    ) {
        $this->workerId = gethostname() . ':' . getmypid();
    }

    public function run(bool $once = false): void
    {
        $this->logger->info("[DeepDive] Worker starting ({$this->workerId})");
        $this->rescueOnBoot();

        $processed = 0;
        while (true) {
            $row = $this->jobs->claimNext($this->workerId);
            if (!$row) {
                if ($once) {
                    $this->logger->info('[DeepDive] no job (once-mode), exiting');
                    return;
                }
                sleep($this->idleSleepSeconds);
                continue;
            }

            $this->processJob($row);
            $processed++;

            if ($once) return;

            // Rotate the worker every N jobs or if memory grows.
            $mbUsed = (int)(memory_get_usage(true) / 1048576);
            if ($processed >= $this->restartAfterJobs || $mbUsed >= $this->restartAfterMemoryMb) {
                $this->logger->info("[DeepDive] Rotating worker (processed={$processed}, mem={$mbUsed}MB)");
                return;
            }
        }
    }

    private function rescueOnBoot(): void
    {
        $rescued = $this->jobs->rescueExpired();
        if ($rescued > 0) {
            $this->logger->warning("[DeepDive] Rescued {$rescued} stuck job(s) at boot");
        }
        $removed = CleanupStep::orphanSweep($this->pdo, $this->logger);
        if ($removed > 0) {
            $this->logger->info("[DeepDive] Orphan sweep removed {$removed} path(s) at boot");
        }
    }

    private function processJob(array $row): void
    {
        $jobId = $row['id'];
        $ids   = $this->parsePgArray((string)($row['debug_file_ids'] ?? ''));

        $this->logger->info("[DeepDive] Processing job {$jobId} for tenant {$row['tenant_id']}");

        // Initialize audit logger for this job
        $auditLogger = new PipelineLogger($jobId);

        // Log job start
        $auditLogger->logStepStart('pipeline', "DeepDive analysis pipeline started for job {$jobId}");

        $steps = JobRepository::initialSteps();
        $ctx = new PipelineContext(
            jobId:       $jobId,
            tenantId:    $row['tenant_id'],
            projectId:   $row['project_id'],
            debugFileIds: $ids,
            debugMode:   (bool)($row['debug_mode'] ?? false),
            pdo:         $this->pdo,
            jobs:        $this->jobs,
            logger:      $this->logger,
            steps:       $steps,
        );

        // Store audit logger in context for use by pipeline steps
        $ctx->bag['audit_logger'] = $auditLogger;

        $pipeline = (new Pipeline($this->logger))
            ->add(new ValidateStep())
            ->add(new ExtractStep())
            ->add(new DecompressStep())
            ->add(new ParseStep())
            ->add(new EvaluateStep())
            ->add(new CorrelateStep())
            ->add(new NarrateStep())
            ->add(new RenderStep())
            ->add(new CleanupStep());

        try {
            $pipeline->run($ctx);

            // Log successful pipeline completion
            $auditLogger->logStepComplete('pipeline', 'DeepDive analysis pipeline completed successfully', [
                'steps_completed' => count($steps),
            ]);

            $report   = $ctx->bag['report'] ?? [];
            $htmlPath = $report['html_path'] ?? null;
            $pdfPath  = $report['pdf_path']  ?? null;

            // Export audit logs after successful completion
            try {
                $logsPath = Paths::root() . '/storage/logs/deepdive';
                $exporter = new LogExporter($auditLogger, $logsPath);
                $logPaths = $exporter->save();
                $ctx->bag['log_paths'] = $logPaths;
                $this->logger->info("[DeepDive] Audit logs saved for {$jobId}");
            } catch (\Throwable $e) {
                $this->logger->warning("[DeepDive] Failed to export audit logs: " . $e->getMessage());
            }

            $this->jobs->markCompleted($jobId, $htmlPath, $pdfPath, $ctx->asArray());
            $this->logger->info("[DeepDive] Completed {$jobId}");
        } catch (\Throwable $e) {
            // Log pipeline error
            $auditLogger->logError(
                'Pipeline execution failed: ' . $e->getMessage(),
                'exception',
                [
                    'exception' => get_class($e),
                    'file' => basename($e->getFile()),
                    'line' => $e->getLine(),
                ]
            );

            $this->logger->error("[DeepDive] Job {$jobId} failed: " . $e->getMessage(), [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);

            // Export audit logs even on failure for debugging
            try {
                $logsPath = Paths::root() . '/storage/logs/deepdive';
                $exporter = new LogExporter($auditLogger, $logsPath);
                $logPaths = $exporter->save();
                $ctx->bag['log_paths'] = $logPaths;
                $this->logger->info("[DeepDive] Audit logs saved for failed job {$jobId}");
            } catch (\Throwable $e2) {
                $this->logger->warning("[DeepDive] Failed to export audit logs: " . $e2->getMessage());
            }

            $this->jobs->markFailed($jobId, $e->getMessage(), $ctx->asArray());
            // Best-effort cleanup on hard fail (unless debug mode).
            if (!$ctx->debugMode) {
                try {
                    (new CleanupStep())->run($ctx);
                } catch (\Throwable $ignored) {
                    $this->logger->warning('[DeepDive] post-fail cleanup failed: ' . $ignored->getMessage());
                }
            }
        }
    }

    private function parsePgArray(string $pg): array
    {
        if ($pg === '' || $pg === '{}') return [];
        return array_values(array_filter(explode(',', trim($pg, '{}'))));
    }
}
