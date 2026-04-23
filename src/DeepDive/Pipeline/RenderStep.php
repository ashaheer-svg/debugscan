<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Report\PdfExporter;
use App\DeepDive\Report\ReportRenderer;
use App\DeepDive\Support\Engine;
use App\DeepDive\Support\Paths;

/**
 * Produces the final single-file HTML report via ReportRenderer.
 *
 * Source of truth for narrative / recommended_actions is the DB row
 * (populated by NarrateStep). We pass those through as a side-channel
 * overlay on the renderer. Incidents themselves come straight from the
 * pipeline bag — they were persisted in CorrelateStep but reading them
 * back would only add a DB round-trip with no extra information.
 *
 * PDF rendering is Sprint 4's concern; this step writes HTML only.
 */
final class RenderStep implements StepInterface
{
    public function id(): string { return 'render'; }

    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        Paths::ensure(Paths::reportsDir());
        $htmlPath = Paths::reportHtml($ctx->jobId);

        $incidents = $ctx->bag['incidents'] ?? [];
        $incidents = is_array($incidents) ? $incidents : [];

        $renderer  = new ReportRenderer();
        $renderer->setDbOverlay($this->loadOverlay($ctx));

        // Catalogue is best-effort: if it fails to load, the renderer degrades
        // to showing just rule IDs with no description/remediation fallback.
        $catalogue = Engine::loadCatalogue();

        $context = [
            'job_id'            => $ctx->jobId,
            'tenant_id'         => $ctx->tenantId,
            'project_id'        => $ctx->projectId,
            'generated_at'      => date('Y-m-d H:i:s T'),
            'engine_version'    => Engine::VERSION,
            'report_version'    => '2.9.0',  // Fetch-based progress modal, no page reload; modal auto-closes on completion
            'catalogue_version' => $ctx->bag['rule_catalogue_version'] ?? Engine::catalogueVersion($catalogue),
            'bundles'           => $ctx->bag['bundles'] ?? [],
            'evaluator_errors'  => $ctx->bag['evaluator_errors'] ?? [],
        ];

        try {
            $html = $renderer->render($incidents, $context, $catalogue);
        } catch (\Throwable $e) {
            $ctx->softFailStep($this->id(), 'Renderer threw: ' . $e->getMessage());
            return;
        }

        if (file_put_contents($htmlPath, $html) === false) {
            throw new \RuntimeException("Failed to write report: {$htmlPath}");
        }

        // PDF is a best-effort companion. If mPDF isn't installed or throws,
        // the HTML report alone is enough for the download flow to succeed.
        $pdfPath = Paths::reportPdf($ctx->jobId);
        $pdfOk   = false;
        try {
            $exporter = new PdfExporter($ctx->logger);
            if ($exporter->isAvailable()) {
                $pdfOk = $exporter->renderToFile($html, $pdfPath, $ctx->jobId);
            }
        } catch (\Throwable $e) {
            $ctx->logger->warning('[deepdive.render] pdf exporter threw: ' . $e->getMessage());
        }

        $ctx->bag['report']['html_path'] = $htmlPath;
        $ctx->bag['report']['pdf_path']  = $pdfOk ? $pdfPath : null;

        $ctx->stepDetail($this->id(), sprintf(
            '%d incident(s) rendered (%s)',
            count($incidents),
            $pdfOk ? 'HTML + PDF' : 'HTML only'
        ));
        $ctx->completeStep($this->id());
    }

    /**
     * Pull narrative + recommended_actions from deepdive_incidents. Missing
     * rows just mean no overlay — renderer will fall back to rule text.
     *
     * @return array<string,array{narrative:?string, recommended_actions:mixed}>
     */
    private function loadOverlay(PipelineContext $ctx): array
    {
        $out = [];
        try {
            $stmt = $ctx->pdo->prepare("
                SELECT id, narrative, recommended_actions
                FROM deepdive_incidents
                WHERE deepdive_job_id = :job AND tenant_id = :tid
            ");
            $stmt->execute(['job' => $ctx->jobId, 'tid' => $ctx->tenantId]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[(string)$row['id']] = [
                    'narrative'            => $row['narrative'],
                    'recommended_actions'  => $row['recommended_actions'],
                ];
            }
        } catch (\Throwable $e) {
            $ctx->logger->warning('[deepdive.render] overlay load failed: ' . $e->getMessage());
        }
        return $out;
    }
}
