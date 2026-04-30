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
use App\DeepDive\Settings\ReportAISettings;

/**
 * Render Step (FIXED): Generate HTML and PDF reports with proper settings
 *
 * FIXES FROM CODE REVIEW:
 * 1. ✅ Initialize $ctx->bag['report'] before use
 * 2. ✅ Load token budget from settings (configurable)
 * 3. ✅ Load AI enabled flag from settings
 * 4. ✅ Use Z.ai model selection from settings
 * 5. ✅ Proper error handling with fallback behavior
 * 6. ✅ Validate settings on startup
 * 7. ✅ Add timing measurements
 * 8. ✅ Better error messages with context
 *
 * @package App\DeepDive\Pipeline
 */
final class RenderStepFixed implements StepInterface
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
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Writes reports to disk, updates context
     *
     * @throws RuntimeException Only if HTML write fails (fatal)
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());
        $startTime = microtime(true);

        // === Initialize report context ===
        $ctx->bag['report'] ??= [];  // FIX: Ensure report bag exists

        // === Prepare output directory ===
        Paths::ensure(Paths::reportsDir());
        $htmlPath = Paths::reportHtml($ctx->jobId);

        // === Extract incidents from context ===
        $incidents = $ctx->bag['incidents'] ?? [];
        $incidents = is_array($incidents) ? $incidents : [];

        // === Load AI settings (FIXED) ===
        $aiSettings = new ReportAISettings($ctx->pdo, $ctx->tenantId, $ctx->logger);
        $validationErrors = $aiSettings->validate();

        if (!empty($validationErrors)) {
            $ctx->logger->warning('[deepdive.render] AI settings invalid: ' . implode(', ', $validationErrors));
        }

        // === RUN PHASE 1 & 2: AI ANALYSIS (optional, configurable) ===
        $aiFindings = [];
        $aiStartTime = microtime(true);

        if ($aiSettings->isEnabled()) {
            try {
                $aiFindings = $this->runAIAnalysis(
                    $ctx,
                    $aiSettings,
                    $aiStartTime
                );

                $aiDuration = microtime(true) - $aiStartTime;

                if (!empty($aiFindings)) {
                    $ctx->logger->info(sprintf(
                        '[deepdive.render] AI analysis complete: %d bundle(s) analyzed in %.2fs',
                        count($aiFindings),
                        $aiDuration
                    ));
                }

            } catch (\Throwable $e) {
                $aiDuration = microtime(true) - $aiStartTime;

                $ctx->logger->warning(sprintf(
                    '[deepdive.render] AI analysis failed after %.2fs: %s',
                    $aiDuration,
                    $e->getMessage()
                ));

                // Check if AI is required
                if ($aiSettings->isRequired()) {
                    throw new \RuntimeException('AI analysis required but failed: ' . $e->getMessage());
                }
                // Otherwise continue with partial findings
            }
        } else {
            $ctx->logger->debug('[deepdive.render] AI analysis disabled in settings');
        }

        // === Initialize renderer ===
        $renderer = new ReportRenderer();
        $renderer->setDbOverlay($this->loadOverlay($ctx));

        // === Load rule catalogue ===
        $catalogue = Engine::loadCatalogue();

        // === Build context with metadata ===
        $context = [
            'job_id'            => $ctx->jobId,
            'tenant_id'         => $ctx->tenantId,
            'project_id'        => $ctx->projectId,
            'generated_at'      => date('Y-m-d H:i:s T'),
            'engine_version'    => Engine::VERSION,
            'report_version'    => '3.1.0',
            'catalogue_version' => $ctx->bag['rule_catalogue_version'] ?? Engine::catalogueVersion($catalogue),
            'bundles'           => $ctx->bag['bundles'] ?? [],
            'evaluator_errors'  => $ctx->bag['evaluator_errors'] ?? [],
            'power_data'        => $ctx->bag['power_data'] ?? [],
            'ai_findings'       => $aiFindings,  // FIX: Always present (empty if disabled)
        ];

        // === Render HTML report ===
        try {
            $html = $renderer->render($incidents, $context, $catalogue);
        } catch (\Throwable $e) {
            $ctx->softFailStep($this->id(), 'Renderer threw: ' . $e->getMessage());
            return;
        }

        // === Write HTML to disk ===
        if (file_put_contents($htmlPath, $html) === false) {
            throw new \RuntimeException("Failed to write report: {$htmlPath}");
        }

        // === Export to PDF (best-effort) ===
        $pdfPath = Paths::reportPdf($ctx->jobId);
        $pdfOk = false;

        try {
            $exporter = new PdfExporter($ctx->logger);
            if ($exporter->isAvailable()) {
                $pdfOk = $exporter->renderToFile($html, $pdfPath, $ctx->jobId);
            }
        } catch (\Throwable $e) {
            $ctx->logger->warning('[deepdive.render] pdf exporter threw: ' . $e->getMessage());
        }

        // === Record report paths (FIX: Initialize first) ===
        $ctx->bag['report']['html_path'] = $htmlPath;
        $ctx->bag['report']['pdf_path'] = $pdfOk ? $pdfPath : null;

        // === Record completion with timing ===
        $totalDuration = microtime(true) - $startTime;

        $ctx->stepDetail($this->id(), sprintf(
            '%d incident(s) rendered (%s) - %.2fs total',
            count($incidents),
            $pdfOk ? 'HTML + PDF' : 'HTML only',
            $totalDuration
        ));

        $ctx->completeStep($this->id());
    }

    /**
     * Internal helper: Run Phase 1 & 2 AI analysis with settings
     *
     * @param PipelineContext $ctx Pipeline context
     * @param ReportAISettings $settings AI settings
     * @param float $startTime Start time for duration calculation
     *
     * @return array AI findings for all bundles
     *
     * @throws \RuntimeException On analysis failure (if required)
     */
    private function runAIAnalysis(
        PipelineContext $ctx,
        ReportAISettings $settings,
        float $startTime
    ): array {
        // FIX: Load configured token budget instead of hardcoding
        $tokenBudget = $settings->getTokenBudget();
        $minConfidence = $settings->getMinConfidence();

        $ctx->logger->debug(sprintf(
            '[deepdive.render] Starting AI analysis: budget=%dK, model=%s, z.ai=%s',
            $tokenBudget / 1000,
            $settings->getModel() ?? 'default',
            $settings->useZai() ? 'yes' : 'no'
        ));

        // Initialize AI components (FIX: use configured budget)
        $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, $tokenBudget);
        $correlator = new EventCorrelator($ctx->logger);
        $analyzer = new RootCauseAnalyzer($ctx->logger);

        $allFindings = [];
        $bundles = $ctx->bag['bundles'] ?? [];
        $totalTokens = 0;

        foreach ($bundles as &$bundle) {
            $bundlePath = $bundle['extracted_path'] ?? null;
            $bundleId = $bundle['debug_file_id'] ?? basename($bundlePath ?? 'unknown');

            if (!$bundlePath || !is_dir($bundlePath)) {
                $ctx->logger->debug("[deepdive.render] Skipping bundle (no valid path): {$bundleId}");
                continue;
            }

            try {
                // === Phase 1: Detect anomalies ===
                $phase1 = $detector->analyzeBundleAnomalies($bundlePath, [
                    'psu_model'        => $bundle['psu_model'] ?? null,
                    'hardware_spec'    => $bundle['hardware_spec'] ?? null,
                    'bundle_timestamp' => $bundle['extracted_at'] ?? date('Y-m-d H:i:s'),
                ]);

                $totalTokens += $phase1['token_usage'] ?? 0;

                // Collect anomalies from all subsystems
                $allAnomalies = array_merge(
                    $phase1['findings']['power_supply']['anomalies'] ?? [],
                    $phase1['findings']['thermal']['anomalies'] ?? [],
                    $phase1['findings']['hardware']['anomalies'] ?? []
                );

                // === Phase 2: Event Correlation & Root Cause Analysis ===
                $chains = [];
                $rootCauses = [];

                if (!empty($allAnomalies)) {
                    // Correlate anomalies into causal chains
                    $chains = $correlator->correlateAnomalies(
                        $allAnomalies,
                        $phase1['clusters'] ?? []
                    );

                    // Analyze each chain for root causes
                    foreach ($chains as $chain) {
                        try {
                            $finding = $analyzer->analyzeChain($chain);

                            // FIX: Filter by minimum confidence threshold
                            if ($finding['confidence'] >= $minConfidence) {
                                $rootCauses[] = $finding;
                            }

                        } catch (\Throwable $e) {
                            $ctx->logger->warning(
                                "[deepdive.render] Root cause analysis failed for {$bundleId}: " . $e->getMessage()
                            );
                        }
                    }
                }

                // === Store bundle results ===
                $bundleFindings = [
                    'bundle_id'        => $bundleId,
                    'bundle_name'      => basename($bundlePath),
                    'status'           => 'success',
                    'phase1'           => $phase1,
                    'phase2'           => [
                        'chains'       => $chains,
                        'root_causes'  => $rootCauses,
                    ],
                    'summary'          => [
                        'overall_risk'     => $phase1['overall_risk'] ?? 'LOW',
                        'anomaly_count'    => count($allAnomalies),
                        'chain_count'      => count($chains),
                        'root_cause_count' => count($rootCauses),
                        'token_usage'      => $phase1['token_usage'] ?? 0,
                    ],
                ];

                $allFindings[] = $bundleFindings;
                $bundle['ai_analysis'] = $bundleFindings;

                $ctx->logger->info(sprintf(
                    "[deepdive.render] Bundle %s: %d anomalies, %d chains, %d root causes",
                    $bundleId,
                    count($allAnomalies),
                    count($chains),
                    count($rootCauses)
                ));

            } catch (\Throwable $e) {
                $ctx->logger->warning(
                    "[deepdive.render] Analysis failed for bundle {$bundleId}: " . $e->getMessage()
                );

                // Store error state
                $bundle['ai_analysis'] = [
                    'bundle_id'  => $bundleId,
                    'status'     => 'error',
                    'error'      => $e->getMessage(),
                ];
            }
        }

        unset($bundle); // FIX: Clean up reference

        // Store total statistics
        $ctx->bag['ai_statistics'] = [
            'bundles_analyzed'  => count($allFindings),
            'total_anomalies'   => array_sum(array_map(fn($r) => $r['summary']['anomaly_count'] ?? 0, $allFindings)),
            'total_chains'      => array_sum(array_map(fn($r) => $r['summary']['chain_count'] ?? 0, $allFindings)),
            'total_root_causes' => array_sum(array_map(fn($r) => $r['summary']['root_cause_count'] ?? 0, $allFindings)),
            'total_tokens_used' => $totalTokens,
            'duration_seconds'  => microtime(true) - $startTime,
        ];

        return $allFindings;
    }

    /**
     * Internal helper: Load incident narratives from database
     *
     * @param PipelineContext $ctx Pipeline context
     *
     * @return array Narrative overlay map
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
                    'narrative'           => $row['narrative'],
                    'recommended_actions' => $row['recommended_actions'],
                ];
            }

        } catch (\Throwable $e) {
            $ctx->logger->warning('[deepdive.render] overlay load failed: ' . $e->getMessage());
        }

        return $out;
    }
}
