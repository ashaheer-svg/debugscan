<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

/**
 * Pipeline Step Interface: Contract for processing stages
 *
 * PURPOSE:
 * Defines the contract for all steps in the DeepDive analysis pipeline.
 * Each step represents a discrete processing phase (decompress, parse, extract, etc.)
 * executed sequentially by the Pipeline orchestrator.
 *
 * PIPELINE FLOW:
 * Pipeline → DecompressStep → ParseStep → ExtractStep → ValidateStep →
 * CorrelateStep → EvaluateStep → NarrateStep → RenderStep → CleanupStep
 *
 * STEP LIFECYCLE:
 * 1. Pipeline instantiates step class
 * 2. Calls id() to verify step identity
 * 3. Passes PipelineContext object with shared state
 * 4. Calls run() to execute step logic
 * 5. Step modifies context or logs progress
 * 6. Step returns (pipeline continues to next step)
 *
 * FAILURE HANDLING:
 * Two failure modes with different recovery strategies:
 *
 * SOFT FAILURES (continue pipeline):
 * - Use $ctx->markSkipped() to indicate step was skipped
 * - Use $ctx->softFail() to indicate recoverable error
 * - Return normally (no exception thrown)
 * - Pipeline continues to next step
 * - Report includes skip reason or error message
 * - Examples: Missing optional data file, malformed section
 *
 * HARD FAILURES (stop pipeline):
 * - Throw exception for fatal errors
 * - Pipeline stops, job marked as failed
 * - Error message stored in job record
 * - Use only for errors that make remaining steps meaningless
 * - Examples: Corrupted archive, cannot decompress, database error
 *
 * CONTEXT SHARING:
 * PipelineContext object shared across all steps:
 * - Holds extracted data, analysis results, metadata
 * - Tracks progress (current stage, percentage complete)
 * - Accumulates log messages and findings
 * - Each step reads/writes shared context
 *
 * IMPLEMENTATION NOTES:
 * - Steps should be stateless (all state in PipelineContext)
 * - Steps should be reusable (no side effects outside context)
 * - Steps should be testable in isolation
 * - Steps should document what they expect in context
 *
 * @package App\DeepDive\Pipeline
 */
interface StepInterface
{
    /**
     * Get unique step identifier
     *
     * USAGE:
     * - Must match ID from JobRepository::initialSteps() array
     * - Used for progress tracking and job status
     * - Enables skipping or re-running specific steps
     *
     * EXAMPLES:
     * - 'decompress' → DecompressStep
     * - 'parse' → ParseStep
     * - 'extract' → ExtractStep
     * - 'correlate' → CorrelateStep
     * - 'render' → RenderStep
     *
     * @return string Unique step identifier (lowercase, no spaces)
     */
    public function id(): string;

    /**
     * Execute step logic with shared pipeline context
     *
     * EXECUTION CONTEXT:
     * - Called by Pipeline::execute() in sequence
     * - Receives PipelineContext with data from previous steps
     * - Modifies context with results/findings
     * - Called once per job (not retried on failure)
     *
     * FAILURE HANDLING RULES:
     *
     * SOFT FAILURE (recoverable):
     * - Call $ctx->markSkipped() if step cannot execute
     * - Call $ctx->softFail($message) for errors that don't block pipeline
     * - Return normally (do NOT throw)
     * - Pipeline continues to next step
     *
     * HARD FAILURE (fatal):
     * - Throw Exception for unrecoverable errors
     * - Pipeline stops immediately
     * - Job marked as 'failed' with error message
     * - Only use when remaining steps cannot execute
     *
     * EXPECTED CONTEXT MODIFICATIONS:
     * - Read: Job metadata, extracted data from prior steps
     * - Write: New findings, extracted data, analysis results
     * - Log: Progress messages, diagnostic info
     * - Track: Completion percentage, current stage
     *
     * @param PipelineContext $ctx Shared context with job data and state
     *
     * @return void No return value; all results stored in context
     *
     * @throws Exception For hard failures that should stop pipeline
     */
    public function run(PipelineContext $ctx): void;
}
