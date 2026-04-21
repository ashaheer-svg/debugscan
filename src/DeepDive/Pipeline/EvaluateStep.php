<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Rules\Evaluator;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

/**
 * Runs the rule catalogue against the SourceRegistry produced by ParseStep.
 *
 * Findings are stored in $ctx->bag['findings'] as a list of FindingRecord
 * objects. CorrelateStep (Sprint 3a) groups them into incidents; RenderStep
 * currently just counts them in the summary.
 *
 * Errors from individual malformed rules do not fail the pipeline — the
 * evaluator catches and reports them in $ctx->bag['evaluator_errors'].
 */
final class EvaluateStep implements StepInterface
{
    public function id(): string { return 'evaluate'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        $registry = $ctx->bag['source_registry'] ?? null;
        if (!$registry instanceof SourceRegistry) {
            // ParseStep hasn't produced a registry yet (pre-Sprint-2b) — skip cleanly.
            $ctx->bag['findings']          = [];
            $ctx->bag['evaluator_errors']  = [];
            $ctx->skipStep($this->id(), 'No SourceRegistry produced by parse step (parsers not yet wired)');
            return;
        }

        $cat = Engine::loadCatalogue();
        if ($cat === null || $cat->count() === 0) {
            $ctx->bag['findings']         = [];
            $ctx->bag['evaluator_errors'] = [];
            $ctx->skipStep($this->id(), 'Rule catalogue is empty or failed to load');
            return;
        }

        $ctx->bag['rule_catalogue_version'] = $cat->version();

        $ev = new Evaluator();
        $findings = $ev->evaluate($cat, $registry);

        $ctx->bag['findings']         = $findings;
        $ctx->bag['evaluator_errors'] = $ev->errors();

        $detail = sprintf(
            '%d finding(s) from %d rule(s)%s',
            count($findings),
            $cat->count(),
            $ev->errors() === [] ? '' : ', ' . count($ev->errors()) . ' rule error(s)'
        );
        $ctx->stepDetail($this->id(), $detail);
        $ctx->completeStep($this->id());
    }
}
