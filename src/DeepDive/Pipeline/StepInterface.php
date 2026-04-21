<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

interface StepInterface
{
    /** Unique step id matching JobRepository::initialSteps(). */
    public function id(): string;

    /**
     * Run the step. MUST NOT throw on soft failures — use $ctx->markSkipped()
     * or $ctx->softFail() and return. Hard failures (would make the rest of the
     * pipeline meaningless) may throw.
     */
    public function run(PipelineContext $ctx): void;
}
