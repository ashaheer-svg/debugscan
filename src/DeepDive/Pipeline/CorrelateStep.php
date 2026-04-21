<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Correlation\Correlator;
use App\DeepDive\Services\IncidentRepository;

/**
 * Turns a flat list of FindingRecords into a ranked list of Incidents.
 * Persists them immediately so the report renderer (and a future retry
 * that skips straight to render) can rely on the DB as source of truth.
 */
final class CorrelateStep implements StepInterface
{
    public function id(): string { return 'correlate'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        $findings = $ctx->bag['findings'] ?? [];
        if (!is_array($findings) || $findings === []) {
            $ctx->bag['incidents'] = [];
            $ctx->skipStep($this->id(), 'No findings produced by evaluate step');
            return;
        }

        $correlator = new Correlator();
        $incidents  = $correlator->correlate($findings);

        // Persist now so the render step & downstream tools can read them
        // from Postgres without round-tripping the bag.
        try {
            $repo = new IncidentRepository($ctx->pdo);
            $written = $repo->persist($ctx->jobId, $ctx->tenantId, $incidents);
        } catch (\Throwable $e) {
            $ctx->bag['incidents'] = $incidents;
            $ctx->softFailStep($this->id(), 'Correlator ran but DB persist failed: ' . $e->getMessage());
            return;
        }

        $ctx->bag['incidents'] = $incidents;
        $ctx->stepDetail($this->id(), sprintf(
            '%d incident(s) from %d finding(s), %d rows written',
            count($incidents), count($findings), $written
        ));
        $ctx->completeStep($this->id());
    }
}
