<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Models\JobStep;
use App\DeepDive\Services\JobRepository;
use App\DeepDive\Support\Paths;
use PDO;
use Psr\Log\LoggerInterface;

/**
 * Mutable state passed through every pipeline step. Keeps the Pipeline
 * class dumb: each step pulls what it needs, writes what it produces,
 * and reports progress through helpers here.
 */
final class PipelineContext
{
    /** @var JobStep[] keyed by id */
    public array $steps = [];

    /** Scratch area for facts produced by steps (parse → evaluate → correlate → render). */
    public array $bag = [
        'bundles'   => [],   // [{ debug_file_id, extracted_path, upload_path }]
        'facts'     => [],   // parser output
        'findings'  => [],   // rule evaluator output
        'incidents' => [],   // after correlate
        'report'    => [],   // { html_path, pdf_path }
    ];

    public function __construct(
        public readonly string          $jobId,
        public readonly string          $tenantId,
        public readonly string          $projectId,
        public readonly array           $debugFileIds,
        public readonly bool            $debugMode,
        public readonly PDO             $pdo,
        public readonly JobRepository   $jobs,
        public readonly LoggerInterface $logger,
        array $steps,
    ) {
        foreach ($steps as $step) {
            $this->steps[$step->id] = $step;
        }
    }

    public function startStep(string $id, ?string $detail = null): void
    {
        $step = $this->step($id);
        $step->status    = JobStep::RUNNING;
        $step->startedAt = time();
        $step->detail    = $detail;
        $this->flush("Running: {$step->label}");
    }

    public function stepDetail(string $id, string $detail): void
    {
        $this->step($id)->detail = $detail;
        $this->flush(null);
    }

    public function completeStep(string $id): void
    {
        $step = $this->step($id);
        $step->status     = JobStep::DONE;
        $step->finishedAt = time();
        $this->flush("Done: {$step->label}");
    }

    public function skipStep(string $id, string $reason): void
    {
        $step = $this->step($id);
        $step->status     = JobStep::SKIPPED;
        $step->finishedAt = time();
        $step->detail     = $reason;
        $this->logger->info("[{$this->jobId}] step {$id} skipped: {$reason}");
        $this->flush("Skipped: {$step->label}");
    }

    public function softFailStep(string $id, string $error): void
    {
        $step = $this->step($id);
        $step->status     = JobStep::FAILED;
        $step->finishedAt = time();
        $step->error      = $error;
        $this->logger->warning("[{$this->jobId}] step {$id} soft-failed: {$error}");
        $this->flush("Soft failure: {$step->label}");
    }

    public function step(string $id): JobStep
    {
        if (!isset($this->steps[$id])) {
            throw new \RuntimeException("Unknown pipeline step: {$id}");
        }
        return $this->steps[$id];
    }

    public function asArray(): array
    {
        return array_values(array_map(fn(JobStep $s) => $s->toArray(), $this->steps));
    }

    /**
     * Progress = sum of weights of DONE steps / total weight.
     * RUNNING step counts half.
     */
    public function percent(): int
    {
        $total = 0;
        $done  = 0.0;
        foreach ($this->steps as $s) {
            $total += $s->weight;
            $done  += match ($s->status) {
                JobStep::DONE, JobStep::SKIPPED => (float)$s->weight,
                JobStep::RUNNING                 => $s->weight * 0.5,
                default                          => 0.0,
            };
        }
        if ($total === 0) return 0;
        $p = (int)floor(($done / $total) * 100);
        return max(1, min(99, $p));
    }

    public function flush(?string $stage): void
    {
        $pct = $this->percent();
        $this->jobs->updateProgress(
            $this->jobId,
            $pct,
            $stage ?? 'Working',
            $this->asArray(),
        );
    }

    public function extractedPath(): string
    {
        return Paths::extracted($this->jobId);
    }

    public function tmpPath(): string
    {
        return Paths::tmp($this->jobId);
    }
}
