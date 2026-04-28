<?php

declare(strict_types=1);

namespace App\DeepDive\Models;

/**
 * JobStep: Pipeline execution checkpoint tracker
 *
 * PURPOSE:
 * Represents a single step in the DeepDive analysis pipeline (validate, decompress,
 * parse, evaluate, correlate, narrate, render, cleanup). Each step tracks:
 * - Current status (pending, running, done, skipped, failed)
 * - Execution timing (start/finish timestamps for progress reporting)
 * - Optional detail message (step-specific context or metadata)
 * - Error information (exception message if step failed)
 *
 * USAGE:
 * Created once per pipeline step, updated in real-time as step progresses.
 * Serialized to deepdive_jobs.progress JSON array for API polling.
 * Used for frontend progress bar rendering and failure diagnosis.
 *
 * STATUS LIFECYCLE:
 * - PENDING: Not yet started, waiting for prerequisites
 * - RUNNING: Currently executing (startedAt populated)
 * - DONE: Completed successfully (finishedAt populated)
 * - SKIPPED: Intentionally bypassed (soft failure recovery)
 * - FAILED: Encountered fatal error (error message populated)
 *
 * WEIGHT SYSTEM:
 * Each step has a weight (default 10) used to calculate progress percentage.
 * Total weights across all steps = 100%, allowing uneven time distribution.
 * Example: decompress=20 (large files), parse=40 (complex parsing), evaluate=40 (rule engine).
 *
 * @package App\DeepDive\Models
 */
final class JobStep
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const SKIPPED = 'skipped';
    public const FAILED  = 'failed';

    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly int    $weight = 10,
        public string          $status = self::PENDING,
        public ?string         $detail = null,
        public ?int            $startedAt = null,
        public ?int            $finishedAt = null,
        public ?string         $error = null,
    ) {}

    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'label'       => $this->label,
            'weight'      => $this->weight,
            'status'      => $this->status,
            'detail'      => $this->detail,
            'started_at'  => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'error'       => $this->error,
        ];
    }
}
