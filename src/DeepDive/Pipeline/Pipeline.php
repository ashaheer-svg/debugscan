<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use Psr\Log\LoggerInterface;

/**
 * Pipeline Orchestrator: Sequentially execute analysis steps
 *
 * PURPOSE:
 * Coordinates execution of pipeline steps (decompress, parse, evaluate, correlate, etc.)
 * Provides error handling, logging, and progress tracking
 * Shared PipelineContext object passes state between steps
 *
 * STEP EXECUTION MODEL:
 * 1. Steps added in order via add()
 * 2. run() executes each step sequentially
 * 3. Each step receives same PipelineContext
 * 4. Steps modify context and mark completion status
 * 5. Pipeline continues to next step
 *
 * ERROR HANDLING:
 * Steps are fail-soft: they catch recoverable errors and continue
 * Pipeline catches exceptions, logs them, and determines if fatal
 * Only 'validate' and 'extract' steps are truly fatal
 * Other steps fail gracefully with soft failure status
 *
 * PROGRESS TRACKING:
 * Each step has status tracked in JobStep records:
 * - RUNNING: Step currently executing
 * - COMPLETED: Step finished successfully
 * - SKIPPED: Step was skipped (no work to do)
 * - FAILED: Step failed (soft failure, pipeline continues)
 *
 * STEP SEQUENCE:
 * 1. validate - Precondition checks and file location
 * 2. decompress - Expand XZ archives
 * 3. parse - Locate data sources and extract hardware
 * 4. evaluate - Apply rules to generate findings
 * 5. correlate - Group findings into incidents
 * 6. narrate - Generate explanations and narratives
 * 7. render - Create HTML/PDF reports
 * 8. cleanup - Final cleanup and temp file removal
 *
 * @package App\DeepDive\Pipeline
 */
final class Pipeline
{
    /** @var StepInterface[] Array of steps to execute in order */
    private array $steps = [];

    /**
     * Constructor: Dependency injection of logger
     *
     * @param LoggerInterface $logger PSR-3 logger for recording events
     */
    public function __construct(private readonly LoggerInterface $logger) {}

    /**
     * Add a step to the pipeline
     *
     * USAGE:
     * $pipeline
     *   ->add(new ValidateStep())
     *   ->add(new DecompressStep())
     *   ->add(new ParseStep())
     *   ...
     *
     * @param StepInterface $step Step to add to end of pipeline
     *
     * @return self Self for method chaining
     */
    public function add(StepInterface $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    /**
     * Execute all pipeline steps sequentially
     *
     * FLOW:
     * 1. For each registered step:
     *    a. Call step->run($ctx)
     *    b. If no exception: continue to next
     *    c. If exception: log and decide if fatal
     * 2. After each step: verify completion status is set
     * 3. On fatal error: throw exception to stop pipeline
     * 4. On soft error: continue to next step
     *
     * ERROR CLASSIFICATION:
     * FATAL STEPS (re-throw exception):
     * - 'validate' - Preconditions must be met
     * - 'extract' - Core extraction logic
     *
     * SOFT STEPS (continue on error):
     * - 'decompress', 'parse', 'evaluate', etc.
     * - Soft failure doesn't block pipeline
     *
     * LOGGING:
     * Errors logged with step ID, exception type, file, line
     * Includes job ID for traceability
     *
     * @param PipelineContext $ctx Shared context passed to all steps
     *
     * @return void No return; all state in context
     *
     * @throws Throwable From fatal steps (validate, extract)
     */
    public function run(PipelineContext $ctx): void
    {
        foreach ($this->steps as $step) {
            $id = $step->id();
            try {
                // Execute the step
                $step->run($ctx);

                // Verify step marked its completion status
                $current = $ctx->step($id);
                // If step didn't mark itself complete, mark it now
                // Ensures progress always advances
                if ($current->status === \App\DeepDive\Models\JobStep::RUNNING) {
                    $ctx->completeStep($id);
                }
            } catch (\Throwable $e) {
                // Log the error with context
                $this->logger->error("[{$ctx->jobId}] step {$id} threw: " . $e->getMessage(), [
                    'exception' => get_class($e),
                    'file'      => basename($e->getFile()),
                    'line'      => $e->getLine(),
                ]);

                // Record soft failure in context
                $ctx->softFailStep($id, $e->getMessage());

                // Re-throw only for truly fatal steps
                // These steps are prerequisites for remaining work
                if (in_array($id, ['validate', 'extract'], true)) {
                    throw $e;
                }
                // For other steps: continue to next (fail gracefully)
            }
        }
    }
}
