<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Rules\Evaluator;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

/**
 * Evaluate Step: Apply rules to parsed data and generate findings
 *
 * PURPOSE:
 * Runs all detection rules against the SourceRegistry (parsed system data).
 * Generates FindingRecord objects for each rule match.
 * Serves as core analysis engine: transforms structured data into security findings.
 *
 * SEQUENCE:
 * Executes after ParseStep (receives source registry), before CorrelateStep
 *
 * DEPENDENCIES:
 * - SourceRegistry: Registry of extracted system data (from ParseStep)
 * - Rule Catalogue: Collection of detection rules (from Engine::loadCatalogue)
 *
 * INPUT:
 * $ctx->bag['source_registry']: SourceRegistry with parsed system data
 *   Example: config files, logs, system stats, hardware info, etc.
 *
 * OUTPUT:
 * $ctx->bag['findings']: Array of FindingRecord objects
 *   Each finding: {rule_id, severity, matched_condition, evidence, ...}
 *
 * SOFT SKIP CONDITIONS:
 * - No SourceRegistry from ParseStep (parsers not yet wired)
 * - Rule catalogue empty or failed to load (no rules available)
 *
 * ERROR HANDLING:
 * Individual rule errors do NOT fail the pipeline:
 * - Evaluator catches exceptions from malformed rules
 * - Reports errors in $ctx->bag['evaluator_errors']
 * - Pipeline continues with valid findings from working rules
 * - Enables incremental rule development without blocking jobs
 *
 * CATALOGUE VERSIONING:
 * Records catalogue version for traceability:
 * - Enables audit trail of which rules generated findings
 * - Allows reproducibility when rules are updated
 *
 * @package App\DeepDive\Pipeline
 */
final class EvaluateStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'evaluate'
     */
    public function id(): string
    {
        return 'evaluate';
    }

    /**
     * Apply rules to parsed data and generate findings
     *
     * FLOW:
     * 1. Record step start
     * 2. Check SourceRegistry exists (from parse step)
     * 3. Soft skip if no registry (parsers not yet implemented)
     * 4. Load rule catalogue
     * 5. Soft skip if catalogue empty
     * 6. Record catalogue version
     * 7. Run evaluator against all rules
     * 8. Collect findings and errors
     * 9. Record completion with statistics
     *
     * RULE EVALUATION:
     * For each rule in catalogue:
     * - Check rule conditions against source data
     * - If matches: generate FindingRecord with evidence
     * - If rule error: catch and record (don't fail pipeline)
     *
     * ERROR RESILIENCE:
     * Errors from individual rules are caught and reported,
     * allowing pipeline to continue with successful rules.
     * This is critical for incremental rule development.
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Populates $ctx->bag['findings'] and evaluator_errors
     */
    public function run(PipelineContext $ctx): void
    {
        // Record step start
        $ctx->startStep($this->id());

        // === Check SourceRegistry exists ===
        // Produced by ParseStep from extracted system data
        $registry = $ctx->bag['source_registry'] ?? null;
        if (!$registry instanceof SourceRegistry) {
            // ParseStep hasn't produced registry yet (parsers not fully implemented)
            // Soft skip: normal condition during development
            $ctx->bag['findings']          = [];
            $ctx->bag['evaluator_errors']  = [];
            $ctx->skipStep($this->id(), 'No SourceRegistry produced by parse step (parsers not yet wired)');
            return;
        }

        // === Load rule catalogue ===
        // Catalogue contains all detection rules
        $cat = Engine::loadCatalogue();
        if ($cat === null || $cat->count() === 0) {
            // Catalogue empty or load failed
            // Soft skip: may be normal during development
            $ctx->bag['findings']         = [];
            $ctx->bag['evaluator_errors'] = [];
            $ctx->skipStep($this->id(), 'Rule catalogue is empty or failed to load');
            return;
        }

        // Record catalogue version for audit trail
        $ctx->bag['rule_catalogue_version'] = $cat->version();

        // === Evaluate rules against data ===
        // Evaluator runs all rules, catches individual errors
        $ev = new Evaluator();
        $findings = $ev->evaluate($cat, $registry);

        // Store results in context
        $ctx->bag['findings']         = $findings;
        $ctx->bag['evaluator_errors'] = $ev->errors();

        // === Record completion with statistics ===
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
