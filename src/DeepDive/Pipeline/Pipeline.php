<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use Psr\Log\LoggerInterface;

/**
 * Runs a sequence of pipeline steps against a PipelineContext.
 * Steps are expected to be fail-soft — only re-throw on fatal issues.
 */
final class Pipeline
{
    /** @var StepInterface[] */
    private array $steps = [];

    public function __construct(private readonly LoggerInterface $logger) {}

    public function add(StepInterface $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    public function run(PipelineContext $ctx): void
    {
        foreach ($this->steps as $step) {
            $id = $step->id();
            try {
                $step->run($ctx);
                $current = $ctx->step($id);
                // If the step ran to completion without marking itself,
                // mark it done so progress advances.
                if ($current->status === \App\DeepDive\Models\JobStep::RUNNING) {
                    $ctx->completeStep($id);
                }
            } catch (\Throwable $e) {
                $this->logger->error("[{$ctx->jobId}] step {$id} threw: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file'      => basename($e->getFile()),
                    'line'      => $e->getLine(),
                ]);
                $ctx->softFailStep($id, $e->getMessage());
                // Abort only on a few truly fatal ids.
                if (in_array($id, ['validate', 'extract'], true)) {
                    throw $e;
                }
            }
        }
    }
}
