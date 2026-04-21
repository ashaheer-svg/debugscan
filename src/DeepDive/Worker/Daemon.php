<?php

declare(strict_types=1);

namespace App\DeepDive\Worker;

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
 * Long-running daemon: polls deepdive_jobs, runs the pipeline, sleeps.
 * Completely separate from workers/scan_worker.php — different PID,
 * different queue table. A crash here cannot affect the existing scan
 * worker.
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

            $report   = $ctx->bag['report'] ?? [];
            $htmlPath = $report['html_path'] ?? null;
            $pdfPath  = $report['pdf_path']  ?? null;

            $this->jobs->markCompleted($jobId, $htmlPath, $pdfPath, $ctx->asArray());
            $this->logger->info("[DeepDive] Completed {$jobId}");
        } catch (\Throwable $e) {
            $this->logger->error("[DeepDive] Job {$jobId} failed: " . $e->getMessage(), [
                'file' => basename($e->getFile()),
                'line' => $e->getLine(),
            ]);
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
