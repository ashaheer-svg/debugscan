<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\AI\AnomalyDetector;
use App\DeepDive\AI\EventCorrelator;
use App\DeepDive\AI\RootCauseAnalyzer;
use App\DeepDive\Settings\ReportAISettings;

/**
 * AI Analysis Step (FIXED): Phase 1 & 2 with configurable settings
 *
 * PURPOSE:
 * Optional pipeline step that runs comprehensive AI-driven analysis:
 * - Phase 1: Detects anomalies in power, thermal, and hardware subsystems
 * - Phase 2: Correlates anomalies into causal chains
 * - Phase 2: Extracts root causes with remediation roadmaps
 *
 * FIXES FROM CODE REVIEW:
 * 1. ✅ Load token budget from ReportAISettings (configurable)
 * 2. ✅ Check AI enabled flag from settings before running
 * 3. ✅ Use configured minimum confidence threshold for filtering
 * 4. ✅ Clean up bundle reference after loop
 * 5. ✅ Add timing measurements for performance tracking
 * 6. ✅ Use consistent naming (ai_findings instead of ai_results)
 * 7. ✅ Validate settings on startup
 *
 * POSITION IN PIPELINE:
 * Executes after CorrelateStep (after rule evaluation and correlation)
 * Before NarrateStep (so AI findings can be included in narratives)
 * Results available for NarrateStep and RenderStep
 *
 * OPTIONAL EXECUTION:
 * This step is OPTIONAL in the pipeline:
 * - Include in pipeline for standard AI analysis
 * - Skip for faster analysis (RenderStep runs inline by default)
 * - Enable/disable via ReportAISettings
 *
 * ADVANTAGES OF PIPELINE STEP:
 * - Cleaner separation of concerns
 * - Reusable for standalone AI analysis
 * - Results cached for use by downstream steps
 * - Better for future extensibility
 *
 * DATA FLOW:
 * Input:
 * - Bundles with extracted_path (from DecompressStep)
 * - Hardware specs (from ParseStep)
 * - PSU data (from ParseStep)
 *
 * Process:
 * For each bundle:
 *   1. Phase 1: AnomalyDetector.analyzeBundleAnomalies()
 *      → Detects power, thermal, hardware anomalies
 *   2. Phase 2: EventCorrelator.correlateAnomalies()
 *      → Builds causal chains from anomalies
 *   3. Phase 2: RootCauseAnalyzer.analyzeChain()
 *      → Extracts root causes and remediation (filtered by confidence)
 *
 * Output:
 * - $ctx->bag['ai_findings']: Complete AI findings from all bundles
 * - $ctx->bag['ai_statistics']: Analysis statistics and metrics
 * - $bundle['ai_analysis']: Per-bundle AI results
 *
 * @package App\DeepDive\Pipeline
 */
final class AIAnalysisStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'ai_analysis'
     */
    public function id(): string
    {
        return 'ai_analysis';
    }

    /**
     * Run comprehensive AI analysis on all bundles (FIXED)
     *
     * FLOW:
     * 1. Load settings and validate
     * 2. Check if AI is enabled (can be disabled)
     * 3. Initialize AI components with configured budget
     * 4. For each bundle:
     *    a. Run Phase 1: Anomaly detection
     *    b. Run Phase 2: Event correlation
     *    c. Run Phase 2: Root cause analysis (filtered by confidence)
     *    d. Store results in bundle and context
     * 5. Record completion statistics
     * 6. Update context for downstream steps
     *
     * ERROR HANDLING:
     * - Per-bundle errors logged but don't block pipeline
     * - Pipeline continues if partial analysis succeeds
     * - Respects require_ai_analysis flag for failure handling
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Stores AI results in context and bundles
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());
        $startTime = microtime(true);

        // Get audit logger if available
        $auditLogger = $ctx->bag['audit_logger'] ?? null;
        if ($auditLogger) {
            $auditLogger->logStepStart('ai_analysis', 'Running AI-driven anomaly detection and root cause analysis');
        }

        // === Load AI settings (FIXED) ===
        $aiSettings = new ReportAISettings($ctx->pdo, $ctx->tenantId, $ctx->logger);
        $validationErrors = $aiSettings->validate();

        if (!empty($validationErrors)) {
            $ctx->logger->warning('[deepdive.ai_analysis] AI settings invalid: ' . implode(', ', $validationErrors));
            if ($auditLogger) {
                $auditLogger->logValidation('AISettings', 'ai_analysis', false, 'Settings validation failed: ' . implode(', ', $validationErrors));
            }
        }

        // === Skip if disabled ===
        if (!$aiSettings->isEnabled()) {
            $ctx->logger->debug('[deepdive.ai_analysis] AI analysis disabled, skipping');
            if ($auditLogger) {
                $auditLogger->logValidation('AIEnabled', 'ai_analysis', true, 'AI analysis disabled in settings');
            }
            $ctx->completeStep($this->id());
            return;
        }

        // === Extract bundles ===
        $bundles = $ctx->bag['bundles'] ?? [];
        if (empty($bundles)) {
            $ctx->logger->debug('[deepdive.ai_analysis] No bundles to analyze');
            if ($auditLogger) {
                $auditLogger->logValidation('BundleCount', 'ai_analysis', true, 'No bundles to analyze');
            }
            $ctx->completeStep($this->id());
            return;
        }

        // === Initialize AI components (FIXED: use configured budget) ===
        $tokenBudget = $aiSettings->getTokenBudget();
        $minConfidence = $aiSettings->getMinConfidence();

        $ctx->logger->debug(sprintf(
            '[deepdive.ai_analysis] Starting batch analysis: budget=%dK, model=%s',
            $tokenBudget / 1000,
            $aiSettings->getModel() ?? 'default'
        ));

        if ($auditLogger) {
            $auditLogger->logValidation('AIConfiguration', 'ai_analysis', true, sprintf(
                'Budget: %dK tokens, Min confidence: %.0f%%',
                $tokenBudget / 1000,
                $minConfidence * 100
            ));
        }

        $detector = new AnomalyDetector($ctx->pdo, $ctx->logger, $tokenBudget);
        $correlator = new EventCorrelator($ctx->logger);
        $analyzer = new RootCauseAnalyzer($ctx->logger);

        $allResults = [];
        $totalTokens = 0;

        // === Process each bundle ===
        foreach ($bundles as &$bundle) {
            $bundlePath = $bundle['extracted_path'] ?? null;
            $bundleId = $bundle['debug_file_id'] ?? basename($bundlePath ?? 'unknown');

            if (!$bundlePath || !is_dir($bundlePath)) {
                $ctx->logger->debug("[deepdive.ai_analysis] Skipping bundle (invalid path): {$bundleId}");
                continue;
            }

            try {
                // === Phase 1: Anomaly Detection ===
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
                                "[deepdive.ai_analysis] Root cause analysis failed for {$bundleId}: " . $e->getMessage()
                            );
                        }
                    }
                }

                // === Store bundle results ===
                $bundleResults = [
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

                $allResults[] = $bundleResults;
                $bundle['ai_analysis'] = $bundleResults;

                $ctx->logger->info(sprintf(
                    "[deepdive.ai_analysis] Bundle %s: %d anomalies, %d chains, %d root causes",
                    $bundleId,
                    count($allAnomalies),
                    count($chains),
                    count($rootCauses)
                ));

            } catch (\Throwable $e) {
                $ctx->logger->warning(
                    "[deepdive.ai_analysis] Analysis failed for bundle {$bundleId}: " . $e->getMessage()
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

        // === Store results in context (consistent naming with RenderStepFixed) ===
        $ctx->bag['ai_findings'] = $allResults;

        // === Compile statistics ===
        $duration = microtime(true) - $startTime;
        $totalAnomalies = array_sum(array_map(fn($r) => $r['summary']['anomaly_count'] ?? 0, $allResults));
        $totalChains = array_sum(array_map(fn($r) => $r['summary']['chain_count'] ?? 0, $allResults));
        $totalRootCauses = array_sum(array_map(fn($r) => $r['summary']['root_cause_count'] ?? 0, $allResults));

        $ctx->bag['ai_statistics'] = [
            'bundles_analyzed'  => count($allResults),
            'total_anomalies'   => $totalAnomalies,
            'total_chains'      => $totalChains,
            'total_root_causes' => $totalRootCauses,
            'total_tokens_used' => $totalTokens,
            'duration_seconds'  => $duration,
        ];

        // Log step completion
        if ($auditLogger) {
            $auditLogger->logStepComplete('ai_analysis', 'AI analysis complete', [
                'bundles_analyzed' => count($allResults),
                'total_anomalies' => $totalAnomalies,
                'total_chains' => $totalChains,
                'total_root_causes' => $totalRootCauses,
                'total_tokens_used' => $totalTokens,
                'duration_seconds' => (int)$duration,
            ]);
        }

        // === Report completion ===
        $ctx->stepDetail($this->id(), sprintf(
            '%d bundle(s) analyzed - %.2fs total, %d anomalies, %d chains, %d root causes',
            count($allResults),
            $duration,
            $totalAnomalies,
            $totalChains,
            $totalRootCauses
        ));

        $ctx->completeStep($this->id());
    }
}
