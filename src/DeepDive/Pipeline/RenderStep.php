<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Report\PdfExporter;
use App\DeepDive\Report\ReportRenderer;
use App\DeepDive\Support\Engine;
use App\DeepDive\Support\Paths;
use App\DeepDive\AI\AnomalyDetector;
use App\DeepDive\AI\EventCorrelator;
use App\DeepDive\AI\RootCauseAnalyzer;
use App\DeepDive\Metrics\MetricsExtractor;
use App\DeepDive\Storage\MetricsPersistence;
use App\DeepDive\Findings\FindingsIndexer;
use App\DeepDive\Analysis\HistoricalAnalyzer;

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
     * 3. RUN PHASE 1 & 2 (inline AI analysis - optional)
     *    - AnomalyDetector: Find power, thermal, hardware issues
     *    - EventCorrelator: Build causal chains
     *    - RootCauseAnalyzer: Extract root causes + remediation
     * 4. Initialize ReportRenderer
     * 5. Load DB overlay (narratives from NarrateStep)
     * 6. Load rule catalogue (best-effort, not fatal if missing)
     * 7. Build context object with metadata + AI findings
     * 8. Render HTML report via ReportRenderer
     * 9. Write HTML to disk
     * 10. Attempt PDF export (optional, best-effort)
     * 11. Record report paths and completion
     *
     * AI ANALYSIS (Phase 1 & 2):
     * Detects anomalies and builds root cause chains
     * Automatically runs for each bundle unless disabled
     * Results stored in context for report rendering
     * Non-fatal: report renders even if AI fails
     *
     * METADATA:
     * Context includes job ID, versions, bundles, errors for report display
     * AI findings include anomalies, chains, root causes, remediation
     * Enables audit trail and reproducibility
     *
     * HTML RENDERING:
     * ReportRenderer produces single-file HTML with embedded CSS/images
     * Includes rule incidents, power supply data, and AI findings
     * Narratives applied as overlay on top of rule text
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

        // === RUN PHASE 1 & 2: AI ANOMALY DETECTION & ROOT CAUSE ANALYSIS ===
        // Optional: Analyzes each bundle for anomalies and root causes
        // Non-fatal: report renders even if AI analysis fails
        $aiFindings = [];
        try {
            $aiFindings = $this->runAIAnalysis($ctx);
            if (!empty($aiFindings)) {
                $ctx->logger->info('[deepdive.render] AI analysis complete: ' . count($aiFindings) . ' bundle(s) analyzed');
            }
        } catch (\Throwable $e) {
            // AI analysis failed: log warning but continue with report
            // Report will render without AI findings
            $ctx->logger->warning('[deepdive.render] AI analysis failed: ' . $e->getMessage());
        }

        // === RUN HISTORICAL ANALYSIS (METRICS, TRENDS, FORECASTS) ===
        // Optional: Extracts metrics, detects recurring issues, forecasts thresholds
        // Non-fatal: report renders even if historical analysis fails
        $historicalData = [];
        try {
            $historicalData = $this->runHistoricalAnalysis($ctx);
            if (!empty($historicalData)) {
                $ctx->logger->info('[deepdive.render] Historical analysis complete');
            }
        } catch (\Throwable $e) {
            // Historical analysis failed: log warning but continue with report
            // Report will render without historical data
            $ctx->logger->warning('[deepdive.render] Historical analysis failed: ' . $e->getMessage());
        }

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
            'report_version'    => '3.1.0',                      // Format version - enhanced power anomaly detection
            'catalogue_version' => $ctx->bag['rule_catalogue_version'] ?? Engine::catalogueVersion($catalogue),
            'bundles'           => $ctx->bag['bundles'] ?? [],   // Bundle metadata
            'evaluator_errors'  => $ctx->bag['evaluator_errors'] ?? [],  // Rule evaluation errors
            'power_data'        => $ctx->bag['power_data'] ?? [],  // Power supply analysis data
            'ai_findings'       => $aiFindings,                  // Phase 1 & 2 AI analysis results
            'historical_data'   => $historicalData,              // Metrics, trends, forecasts, recurring issues
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
     * Internal helper: Run Phase 1 & 2 AI analysis
     *
     * PURPOSE:
     * Runs anomaly detection and root cause analysis on bundles
     * Non-fatal: if analysis fails, report continues without AI findings
     *
     * FLOW:
     * 1. For each bundle:
     *    a. Run Phase 1: AnomalyDetector (power, thermal, hardware)
     *    b. Run Phase 2: EventCorrelator → RootCauseAnalyzer
     *    c. Collect findings with confidence scores
     * 2. Return consolidated findings for all bundles
     *
     * ERROR HANDLING:
     * Bundle analysis errors are logged but don't block pipeline
     * Returns partial results (other bundles still analyzed)
     *
     * @param PipelineContext $ctx Pipeline context
     *
     * @return array<array> AI findings for all bundles
     */
    private function runAIAnalysis(PipelineContext $ctx): array
    {
        // Initialize AI components
        $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, 10000);
        $correlator = new EventCorrelator($ctx->logger);
        $analyzer = new RootCauseAnalyzer($ctx->logger);

        $allFindings = [];
        $bundles = $ctx->bag['bundles'] ?? [];

        foreach ($bundles as &$bundle) {
            $bundlePath = $bundle['extracted_path'] ?? null;
            if (!$bundlePath || !is_dir($bundlePath)) {
                continue;
            }

            try {
                // === Phase 1: Detect anomalies ===
                // Pass complete bundle context for comprehensive anomaly detection
                $phase1 = $detector->analyzeBundleAnomalies($bundlePath, [
                    'psu_model'        => $bundle['psu_model'] ?? null,
                    'hardware_spec'    => $bundle['hardware_spec'] ?? null,
                    'bundle_timestamp' => $bundle['extracted_at'] ?? date('Y-m-d H:i:s'),
                    'power_data'       => $bundle['power_data'] ?? null,           // Parsed power supply data
                    'source_registry'  => $ctx->bag['source_registry'] ?? null,   // Data source registry
                    'facts'            => $ctx->bag['facts'] ?? [],                // Bundle metadata facts
                    'tenant_id'        => $ctx->tenantId,                          // Multi-tenant context
                    'nas_id'           => $ctx->bag['nas_id'] ?? $ctx->jobId,     // NAS identifier
                ]);

                // Collect all anomalies
                $allAnomalies = array_merge(
                    $phase1['findings']['power_supply']['anomalies'] ?? [],
                    $phase1['findings']['thermal']['anomalies'] ?? [],
                    $phase1['findings']['hardware']['anomalies'] ?? []
                );

                // === Phase 2: Correlate and analyze root causes ===
                $chains = [];
                $rootCauses = [];

                if (!empty($allAnomalies)) {
                    // Build causal chains
                    $chains = $correlator->correlateAnomalies(
                        $allAnomalies,
                        $phase1['clusters'] ?? []
                    );

                    // Analyze root causes
                    foreach ($chains as $chain) {
                        try {
                            $rootCauses[] = $analyzer->analyzeChain($chain);
                        } catch (\Throwable $e) {
                            $ctx->logger->warning('[deepdive.render] Root cause analysis failed: ' . $e->getMessage());
                        }
                    }
                }

                // === Store findings ===
                $bundleFindings = [
                    'bundle_id'        => $bundle['debug_file_id'] ?? basename($bundlePath),
                    'bundle_name'      => basename($bundlePath),
                    'phase1'           => $phase1,
                    'chains'           => $chains,
                    'root_causes'      => $rootCauses,
                    'overall_risk'     => $phase1['overall_risk'] ?? 'LOW',
                    'token_usage'      => $phase1['token_usage'] ?? 0,
                ];

                $allFindings[] = $bundleFindings;
                $bundle['ai_analysis'] = $bundleFindings; // Store in bundle for reference

            } catch (\Throwable $e) {
                // Bundle analysis failed: log and continue
                $ctx->logger->warning(sprintf(
                    '[deepdive.render] AI analysis failed for bundle %s: %s',
                    basename($bundlePath),
                    $e->getMessage()
                ));
                continue;
            }
        }

        return $allFindings;
    }

    /**
     * Internal helper: Run historical analysis (metrics, trends, forecasts)
     *
     * PURPOSE:
     * Extracts metrics from bundles, stores in persistence layer,
     * detects recurring issues, analyzes trends, and forecasts thresholds.
     * Non-fatal: if analysis fails, report continues without historical data.
     *
     * FLOW:
     * 1. Initialize persistence layer (MetricsPersistence, FindingsIndexer)
     * 2. For each bundle:
     *    a. Extract metrics via MetricsExtractor
     *    b. Store metrics in timeseries database
     *    c. Check for anomalies against baselines
     *    d. Index findings if anomalies detected
     *    e. Analyze trends from historical data
     *    f. Forecast when thresholds will be exceeded
     * 3. Compile results: recurring issues, trends, forecasts, before/after
     *
     * ERROR HANDLING:
     * Bundle analysis errors are logged but don't block pipeline
     * Returns partial results (other bundles still analyzed)
     *
     * @param PipelineContext $ctx Pipeline context
     *
     * @return array Historical analysis data with recurring_issues, trends, forecasts
     */
    private function runHistoricalAnalysis(PipelineContext $ctx): array
    {
        // Initialize persistence layer
        $extractor = new MetricsExtractor($ctx->logger);
        $persistence = new MetricsPersistence($ctx->pdo, $ctx->logger);
        $findings = new FindingsIndexer($ctx->pdo, $ctx->logger);
        $analyzer = new HistoricalAnalyzer($extractor, $persistence, $findings, $ctx->logger);

        $historicalData = [
            'recurring_issues' => [],
            'trends'           => [],
            'forecasts'        => [],
            'before_after'     => [],
        ];

        $bundles = $ctx->bag['bundles'] ?? [];
        $nasId = $ctx->bag['nas_id'] ?? $ctx->jobId; // Use NAS ID if available, fallback to job ID

        foreach ($bundles as &$bundle) {
            $bundlePath = $bundle['extracted_path'] ?? null;
            if (!$bundlePath || !is_dir($bundlePath)) {
                continue;
            }

            try {
                $bundleDate = $bundle['extracted_at'] ?? date('Y-m-d H:i:s');

                // === Analyze bundle: extract metrics, detect anomalies, index findings ===
                $bundleAnalysis = $analyzer->analyzeBundle($bundle, $ctx->tenantId, $nasId, $bundleDate);

                // Log summary
                $ctx->logger->info(sprintf(
                    '[deepdive.render] Bundle analysis: %d metrics, %d anomalies, %d findings',
                    $bundleAnalysis['metrics_extracted'],
                    $bundleAnalysis['anomalies_detected'],
                    $bundleAnalysis['findings_created']
                ));
            } catch (\Throwable $e) {
                // Analysis failed for this bundle: log and continue
                $ctx->logger->warning(
                    '[deepdive.render] Historical analysis failed for bundle: ' . $e->getMessage()
                );
                continue;
            }
        }

        // === Compile historical analysis results ===
        try {
            // Get recurring issues (occurred multiple times)
            $historicalData['recurring_issues'] = $analyzer->getRecurringIssues($ctx->tenantId, $nasId);

            // Get key metrics for trend analysis
            // Prioritize: memory usage, network errors, RAID status, thermal temps
            $keyMetrics = [
                'memory.used_percent',
                'memory.swap_used_percent',
                'network.total_errors',
                'raid.rebuild_progress_percent',
                'thermal.cpu_temp_celsius',
                'storage.used_percent',
            ];

            foreach ($keyMetrics as $metricName) {
                $trend = $analyzer->analyzeTrend($ctx->tenantId, $nasId, $metricName);
                if ($trend['data_points'] >= 2) {
                    $historicalData['trends'][] = $trend;
                }

                // Forecast for this metric (use baseline alarm threshold)
                $baseline = $persistence->getBaselineProfile($ctx->tenantId, $nasId, $metricName);
                if (!empty($baseline)) {
                    $metric = reset($baseline);
                    $threshold = $metric['alarm_threshold'] ?? null;
                    if ($threshold) {
                        $forecast = $analyzer->forecastThreshold($ctx->tenantId, $nasId, $metricName, (float)$threshold, 30);
                        $historicalData['forecasts'][] = $forecast;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Compilation failed: log but return partial results
            $ctx->logger->warning(
                '[deepdive.render] Failed to compile historical analysis: ' . $e->getMessage()
            );
        }

        return $historicalData;
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
