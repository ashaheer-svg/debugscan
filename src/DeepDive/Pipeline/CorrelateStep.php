<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Correlation\Correlator;
use App\DeepDive\Services\IncidentRepository;

/**
 * Correlate Step: Transform findings into ranked incidents
 *
 * PURPOSE:
 * Converts flat list of individual findings into grouped, ranked incidents.
 * Incidents represent root-cause events that generate multiple symptoms (findings).
 * Performs causal analysis to build incident chains and calculate impact scores.
 *
 * SEQUENCE:
 * Executes after EvaluateStep (receives findings), before NarrateStep
 *
 * INPUT:
 * $ctx->bag['findings']: Array of FindingRecord objects from evaluate step
 *   Each finding: {rule, severity, timestamp, evidence, ...}
 *
 * PROCESSING:
 * 1. Extract findings from context
 * 2. Skip if no findings (soft skip, not failure)
 * 3. Call Correlator::correlate() to group findings into incidents
 * 4. Rank incidents by impact and relevance
 * 5. Persist incidents to database immediately (not deferred)
 * 6. Store in context for downstream steps
 *
 * OUTPUT:
 * $ctx->bag['incidents']: Array of Incident objects
 *   Each incident: {root_cause, findings[], severity, impact_score, ...}
 *
 * DATABASE PERSISTENCE:
 * Persists incidents immediately to database for:
 * - Report renderer to query without context round-tripping
 * - Future pipeline retries to skip straight to render step
 * - Audit trail and incident tracking
 * - Future UI for incident browsing
 *
 * SOFT FAILURE HANDLING:
 * If DB persist fails but correlator succeeded:
 * - Still returns incidents in context (for render step)
 * - Records soft failure (not blocking)
 * - Allows render step to use in-memory incidents
 * - Incident data not persisted, but analysis not lost
 *
 * OPTIMIZATION:
 * Database persistence happens here (not deferred to render) to separate
 * concerns: render step focuses on HTML/PDF generation, not data ops
 *
 * @package App\DeepDive\Pipeline
 */
final class CorrelateStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'correlate'
     */
    public function id(): string
    {
        return 'correlate';
    }

    /**
     * Correlate findings into ranked incidents and persist to database
     *
     * FLOW:
     * 1. Record step start
     * 2. Extract findings from previous step
     * 3. If empty: skip this step (soft skip)
     * 4. Create correlator and analyze findings
     * 5. Persist results to database
     * 6. Store in context for downstream
     * 7. Record completion with statistics
     *
     * SKIP CONDITION:
     * Soft-skip if evaluate step produced no findings
     * This is normal for clean systems with no issues
     *
     * SOFT FAIL CONDITION:
     * If correlator succeeded but database persist fails:
     * - Still returns incidents in context
     * - Allows render step to use in-memory data
     * - Logs soft failure message
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Populates $ctx->bag['incidents'], persists to DB
     */
    public function run(PipelineContext $ctx): void
    {
        // Record step start
        $ctx->startStep($this->id());

        // Get audit logger if available
        $auditLogger = $ctx->bag['audit_logger'] ?? null;
        if ($auditLogger) {
            $auditLogger->logStepStart('correlate', 'Correlating findings into ranked incidents');
        }

        // === Extract findings from evaluate step ===
        $findings = $ctx->bag['findings'] ?? [];

        // === Soft skip if no findings ===
        // Empty findings list is normal for systems with no issues
        if (!is_array($findings) || $findings === []) {
            $ctx->bag['incidents'] = [];
            if ($auditLogger) {
                $auditLogger->logValidation('FindingCorrelation', 'correlate', true, 'No findings to correlate');
            }
            $ctx->skipStep($this->id(), 'No findings produced by evaluate step');
            return;
        }

        if ($auditLogger) {
            $auditLogger->logValidation('FindingExtraction', 'correlate', true, count($findings) . ' finding(s)');
        }

        // === Correlate findings into incidents ===
        // Analyzes causal relationships and groups related findings
        $correlator = new Correlator();
        $incidents  = $correlator->correlate($findings);

        if ($auditLogger) {
            $auditLogger->logDataParsing(
                'Correlator',
                'correlate',
                count($incidents)
            );
        }

        // === Persist incidents to database ===
        // Save immediately so report renderer & downstream tools can read from DB
        // rather than context round-tripping. Enables pipeline retry strategies.
        try {
            $repo = new IncidentRepository($ctx->pdo);
            $written = $repo->persist($ctx->jobId, $ctx->tenantId, $incidents);
        } catch (\Throwable $e) {
            // Database persist failed but correlator succeeded:
            // Still store incidents in context for render step to use
            $ctx->bag['incidents'] = $incidents;
            // Soft failure: allow pipeline to continue with in-memory incidents
            if ($auditLogger) {
                $auditLogger->logError(
                    'Database persist failed: ' . $e->getMessage(),
                    'incident_persist_error',
                    [
                        'incidents' => count($incidents),
                        'exception' => get_class($e),
                    ]
                );
            }
            $ctx->softFailStep($this->id(), 'Correlator ran but DB persist failed: ' . $e->getMessage());
            return;
        }

        // === Success: store incidents and record completion ===
        $ctx->bag['incidents'] = $incidents;

        if ($auditLogger) {
            $auditLogger->logStepComplete('correlate', 'Finding correlation complete', [
                'incidents_created' => count($incidents),
                'findings_correlated' => count($findings),
                'rows_persisted' => $written,
            ]);
        }

        $ctx->stepDetail($this->id(), sprintf(
            '%d incident(s) from %d finding(s), %d rows written',
            count($incidents), count($findings), $written
        ));
        $ctx->completeStep($this->id());
    }
}
