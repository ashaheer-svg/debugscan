<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Report\PdfExporter;
use App\DeepDive\Report\ReportRenderer;
use App\DeepDive\Support\Engine;
use App\DeepDive\Support\Paths;

/**
 * Render Step: Generate HTML and PDF reports
 *
 * PURPOSE:
 * Produces final deliverable reports from analysis results.
 * Renders HTML report with ReportRenderer, optionally exports to PDF.
 * Combines incidents, narratives, and metadata into comprehensive document.
 *
 * SEQUENCE:
 * Executes after NarrateStep (receives incident narratives), before CleanupStep
 *
 * OUTPUT FORMATS:
 * - HTML: Single-file HTML report with embedded CSS/images
 * - PDF: Optional companion PDF (mPDF-based, best-effort)
 *
 * DATA SOURCES:
 * - Incidents: From $ctx->bag['incidents'] (in-memory, fast path)
 * - Narratives: From deepdive_incidents table (DB side-channel overlay)
 * - Metadata: Job info, versions, bundle details, evaluator errors
 * - Catalogue: Rule metadata for context and remediation advice
 *
 * NARRATIVE OVERLAY:
 * Narratives are stored in database by NarrateStep
 * This step loads them as "overlay" - renderer applies on top of rule text
 * Missing narratives don't block rendering (fallback to rule text)
 *
 * PDF AS BEST-EFFORT:
 * PDF export is optional companion:
 * - If mPDF unavailable or throws: HTML-only report is sufficient
 * - Allows reports on systems without PDF rendering library
 * - Pipeline doesn't fail if PDF export unavailable
 *
 * REPORT VERSIONING:
 * Captures versions of:
 * - Report format (3.0.0 - includes RAID failure analysis)
 * - Rule catalogue (for audit trail)
 * - Engine version (for reproducibility)
 *
 * @package App\DeepDive\Pipeline
 */
final class RenderStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'render'
     */
    public function id(): string
    {
        return 'render';
    }

    /**
     * Generate HTML and optionally PDF reports from analysis results
     *
     * FLOW:
     * 1. Ensure report directory exists
     * 2. Extract incidents from context (in-memory, from CorrelateStep)
     * 3. Initialize ReportRenderer
     * 4. Load DB overlay (narratives from NarrateStep)
     * 5. Load rule catalogue (best-effort, not fatal if missing)
     * 6. Build context object with metadata
     * 7. Render HTML report via ReportRenderer
     * 8. Write HTML to disk
     * 9. Attempt PDF export (optional, best-effort)
     * 10. Record report paths and completion
     *
     * METADATA:
     * Context includes job ID, versions, bundles, errors for report display
     * Enables audit trail and reproducibility
     *
     * HTML RENDERING:
     * ReportRenderer produces single-file HTML with embedded CSS/images
     * Narratives applied as overlay on top of rule text
     * If narratives missing: renders with fallback rule descriptions
     *
     * PDF EXPORT:
     * Best-effort using PdfExporter (mPDF-based)
     * If unavailable or fails: HTML report is sufficient
     * Pipeline doesn't fail if PDF unavailable
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Writes reports to disk, updates context
     *
     * @throws RuntimeException Only if HTML write fails (fatal)
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        // === Prepare output directory ===
        Paths::ensure(Paths::reportsDir());
        $htmlPath = Paths::reportHtml($ctx->jobId);

        // === Extract incidents from context ===
        // In-memory from CorrelateStep (fast path, no DB round-trip)
        $incidents = $ctx->bag['incidents'] ?? [];
        $incidents = is_array($incidents) ? $incidents : [];

        // === Initialize renderer ===
        $renderer = new ReportRenderer();
        // Load narratives as overlay (applied on top of rule text)
        $renderer->setDbOverlay($this->loadOverlay($ctx));

        // === Load rule catalogue ===
        // Best-effort: missing catalogue degrades gracefully (no rule descriptions)
        $catalogue = Engine::loadCatalogue();

        // === Build context with metadata ===
        $context = [
            'job_id'            => $ctx->jobId,
            'tenant_id'         => $ctx->tenantId,
            'project_id'        => $ctx->projectId,
            'generated_at'      => date('Y-m-d H:i:s T'),       // Timestamp
            'engine_version'    => Engine::VERSION,              // For reproducibility
            'report_version'    => '3.0.0',                      // Format version
            'catalogue_version' => $ctx->bag['rule_catalogue_version'] ?? Engine::catalogueVersion($catalogue),
            'bundles'           => $ctx->bag['bundles'] ?? [],   // Bundle metadata
            'evaluator_errors'  => $ctx->bag['evaluator_errors'] ?? [],  // Rule evaluation errors
        ];

        // === Render HTML report ===
        try {
            $html = $renderer->render($incidents, $context, $catalogue);
        } catch (\Throwable $e) {
            // Rendering failed: soft failure (HTML generation issue)
            $ctx->softFailStep($this->id(), 'Renderer threw: ' . $e->getMessage());
            return;
        }

        // === Write HTML to disk ===
        if (file_put_contents($htmlPath, $html) === false) {
            // File write failed: fatal error
            throw new \RuntimeException("Failed to write report: {$htmlPath}");
        }

        // === Export to PDF (best-effort) ===
        // PDF is optional companion; failure doesn't block HTML report
        $pdfPath = Paths::reportPdf($ctx->jobId);
        $pdfOk   = false;
        try {
            $exporter = new PdfExporter($ctx->logger);
            // Check if mPDF library is available
            if ($exporter->isAvailable()) {
                $pdfOk = $exporter->renderToFile($html, $pdfPath, $ctx->jobId);
            }
        } catch (\Throwable $e) {
            // PDF export failed: log warning but continue
            // HTML report alone is sufficient for download
            $ctx->logger->warning('[deepdive.render] pdf exporter threw: ' . $e->getMessage());
        }

        // === Record report paths ===
        $ctx->bag['report']['html_path'] = $htmlPath;
        $ctx->bag['report']['pdf_path']  = $pdfOk ? $pdfPath : null;

        // === Record completion ===
        $ctx->stepDetail($this->id(), sprintf(
            '%d incident(s) rendered (%s)',
            count($incidents),
            $pdfOk ? 'HTML + PDF' : 'HTML only'
        ));
        $ctx->completeStep($this->id());
    }

    /**
     * Internal helper: Load incident narratives from database
     *
     * PURPOSE:
     * Retrieves detailed narrative and recommended actions for incidents
     * Persisted by NarrateStep, applied as overlay on report rendering
     *
     * OVERLAY MECHANISM:
     * Narratives are applied ON TOP of rule descriptions:
     * - If narrative exists: display narrative
     * - If narrative missing: fall back to rule description
     * - Allows partial data (some narratives, some fallbacks)
     *
     * ERROR HANDLING:
     * Database errors caught and logged, not fatal
     * Returns empty array on error (uses fallback descriptions)
     *
     * QUERY:
     * SELECT id, narrative, recommended_actions
     * FROM deepdive_incidents
     * WHERE deepdive_job_id = ? AND tenant_id = ?
     *
     * @param PipelineContext $ctx Pipeline context with job/tenant IDs
     *
     * @return array<string,array{narrative:?string, recommended_actions:mixed}>
     *         Map of incident ID to narrative data
     */
    private function loadOverlay(PipelineContext $ctx): array
    {
        $out = [];
        try {
            // Query for incidents with narratives and recommendations
            $stmt = $ctx->pdo->prepare("
                SELECT id, narrative, recommended_actions
                FROM deepdive_incidents
                WHERE deepdive_job_id = :job AND tenant_id = :tid
            ");
            $stmt->execute(['job' => $ctx->jobId, 'tid' => $ctx->tenantId]);

            // Build overlay map: incident ID → narrative data
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $out[(string)$row['id']] = [
                    'narrative'           => $row['narrative'],
                    'recommended_actions' => $row['recommended_actions'],
                ];
            }
        } catch (\Throwable $e) {
            // Database error: log and return empty overlay
            // Renderer will use fallback rule descriptions
            $ctx->logger->warning('[deepdive.render] overlay load failed: ' . $e->getMessage());
        }
        return $out;
    }
}
