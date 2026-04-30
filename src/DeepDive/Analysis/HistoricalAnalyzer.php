<?php

declare(strict_types=1);

namespace App\DeepDive\Analysis;

use Psr\Log\LoggerInterface;
use App\DeepDive\Metrics\MetricsExtractor;
use App\DeepDive\Storage\MetricsPersistence;
use App\DeepDive\Findings\FindingsIndexer;

/**
 * HistoricalAnalyzer: Multi-bundle trend analysis and forecasting
 *
 * PURPOSE:
 * Orchestrates metrics extraction, persistence, and finding indexing to enable:
 * - Trend analysis: Is this metric improving or degrading?
 * - Forecasting: When will we hit the warning/alarm threshold?
 * - Anomaly scoring: How abnormal is this value vs personalized baseline?
 * - Before/after analysis: Did the fix actually help?
 * - Recurring issue detection: Is this problem happening repeatedly?
 *
 * WORKFLOW:
 * 1. Extract metrics from current bundle (MetricsExtractor)
 * 2. Store metrics in timeseries (MetricsPersistence)
 * 3. Check for anomalies vs baseline (MetricsPersistence.isAnomaly)
 * 4. Index findings if anomalies detected (FindingsIndexer)
 * 5. Analyze trends from historical data
 * 6. Forecast future values
 */
final class HistoricalAnalyzer
{
    private MetricsExtractor $extractor;
    private MetricsPersistence $persistence;
    private FindingsIndexer $findings;
    private ?LoggerInterface $logger;

    public function __construct(
        MetricsExtractor $extractor,
        MetricsPersistence $persistence,
        FindingsIndexer $findings,
        ?LoggerInterface $logger = null
    ) {
        $this->extractor = $extractor;
        $this->persistence = $persistence;
        $this->findings = $findings;
        $this->logger = $logger;
    }

    /**
     * Analyze current bundle: extract, persist, detect anomalies
     *
     * @param array $bundle Bundle metadata and paths
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $bundle_date Bundle timestamp (ISO 8601)
     *
     * @return array Analysis results with metrics, anomalies, and findings
     */
    public function analyzeBundle(array $bundle, string $tenant_id, string $nas_id, string $bundle_date): array
    {
        // Extract metrics from bundle
        $metrics = $this->extractor->extractAll($bundle);
        $this->log('info', "Extracted " . count($metrics) . " metrics");

        // Store in timeseries database
        $stored_count = $this->persistence->storeMetrics($tenant_id, $nas_id, $bundle_date, $metrics);
        $this->log('info', "Stored {$stored_count} metrics in persistence");

        // Detect anomalies and create findings
        $anomalies = [];
        $findings = [];

        foreach ($metrics as $metric_name => $data) {
            $value = $data['value'] ?? null;
            if ($value === null) continue;

            $anomaly = $this->persistence->isAnomaly($tenant_id, $nas_id, $metric_name, (float)$value);

            if ($anomaly['is_anomaly']) {
                $anomalies[$metric_name] = $anomaly;

                // Extract component name from metric (e.g., "network.eth0.errors" -> "eth0")
                $parts = explode('.', $metric_name);
                $component = $parts[1] ?? 'system';

                // Index finding
                $finding_id = $this->findings->indexFinding(
                    tenant_id: $tenant_id,
                    nas_id: $nas_id,
                    issue_type: $parts[0] ?? 'system',  // network, memory, raid, etc.
                    severity: $anomaly['severity'],      // normal, warning, alarm
                    description: "{$metric_name}: {$anomaly['reason']}",
                    affected_component: $component,
                    root_cause: null
                );

                $findings[] = $finding_id;
            }
        }

        $this->log('info', "Detected " . count($anomalies) . " anomalies");

        return [
            'metrics_extracted' => count($metrics),
            'metrics_stored' => $stored_count,
            'anomalies_detected' => count($anomalies),
            'anomalies' => $anomalies,
            'findings_created' => count($findings),
            'finding_ids' => $findings,
        ];
    }

    /**
     * Get trend analysis for a metric
     *
     * Analyzes historical data to determine if metric is improving, degrading,
     * or stable.
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $metric_name Metric to analyze
     * @param ?string $start_date Start of analysis period (ISO 8601)
     * @param ?string $end_date End of analysis period (ISO 8601)
     *
     * @return array Trend data with direction, slope, and summary
     */
    public function analyzeTrend(
        string $tenant_id,
        string $nas_id,
        string $metric_name,
        ?string $start_date = null,
        ?string $end_date = null
    ): array {
        $history = $this->persistence->getMetricsRange($tenant_id, $nas_id, $metric_name, $start_date, $end_date);

        if (count($history) < 2) {
            return [
                'metric_name' => $metric_name,
                'data_points' => count($history),
                'trend' => 'insufficient_data',
                'summary' => 'Not enough historical data for trend analysis',
            ];
        }

        // Calculate trend using linear regression
        $values = array_map(fn($h) => $h['value'], $history);
        $n = count($values);
        $x_avg = ($n - 1) / 2;  // Indices average
        $y_avg = array_sum($values) / $n;

        $numerator = 0;
        $denominator = 0;

        for ($i = 0; $i < $n; $i++) {
            $numerator += ($i - $x_avg) * ($values[$i] - $y_avg);
            $denominator += ($i - $x_avg) ** 2;
        }

        $slope = $denominator > 0 ? $numerator / $denominator : 0;

        // Determine trend direction
        $trend = 'stable';
        if ($slope > 0.1) {
            $trend = 'increasing';
        } elseif ($slope < -0.1) {
            $trend = 'decreasing';
        }

        // Calculate statistics
        $min_value = min($values);
        $max_value = max($values);
        $current_value = end($values);

        return [
            'metric_name' => $metric_name,
            'data_points' => $n,
            'trend' => $trend,
            'slope' => round($slope, 4),
            'min_value' => $min_value,
            'max_value' => $max_value,
            'current_value' => $current_value,
            'first_value' => reset($values),
            'last_value' => end($values),
            'percent_change' => reset($values) > 0
                ? round(((end($values) - reset($values)) / reset($values)) * 100, 2)
                : 0,
            'summary' => $this->formatTrendSummary($metric_name, $trend, $slope, $current_value),
        ];
    }

    /**
     * Forecast when metric will exceed threshold
     *
     * Uses linear trend to estimate when a metric will hit warning/alarm threshold.
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $metric_name Metric to forecast
     * @param float $threshold Threshold value (warning or alarm)
     * @param int $days_lookback Historical days to use for projection
     *
     * @return array Forecast data with projected date and confidence
     */
    public function forecastThreshold(
        string $tenant_id,
        string $nas_id,
        string $metric_name,
        float $threshold,
        int $days_lookback = 30
    ): array {
        $end_date = date('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days_lookback} days"));

        $history = $this->persistence->getMetricsRange(
            $tenant_id,
            $nas_id,
            $metric_name,
            $start_date,
            $end_date
        );

        if (count($history) < 2) {
            return [
                'metric_name' => $metric_name,
                'threshold' => $threshold,
                'status' => 'insufficient_data',
                'message' => 'Not enough historical data for forecasting',
            ];
        }

        $values = array_map(fn($h) => $h['value'], $history);
        $current_value = end($values);

        // Already exceeded?
        if ($current_value >= $threshold) {
            return [
                'metric_name' => $metric_name,
                'threshold' => $threshold,
                'current_value' => $current_value,
                'status' => 'exceeded',
                'message' => 'Threshold already exceeded',
                'days_until' => 0,
            ];
        }

        // Calculate trend
        $n = count($values);
        $x_avg = ($n - 1) / 2;
        $y_avg = array_sum($values) / $n;

        $numerator = 0;
        $denominator = 0;

        for ($i = 0; $i < $n; $i++) {
            $numerator += ($i - $x_avg) * ($values[$i] - $y_avg);
            $denominator += ($i - $x_avg) ** 2;
        }

        $slope = $denominator > 0 ? $numerator / $denominator : 0;

        // If not increasing, won't exceed
        if ($slope <= 0) {
            return [
                'metric_name' => $metric_name,
                'threshold' => $threshold,
                'current_value' => $current_value,
                'status' => 'decreasing',
                'message' => 'Metric is not increasing, threshold unlikely to be reached',
                'slope' => round($slope, 4),
            ];
        }

        // Project days until threshold
        $diff = $threshold - $current_value;
        $days_until = (int)ceil($diff / $slope);

        $projected_date = date('Y-m-d', strtotime("+{$days_until} days"));

        return [
            'metric_name' => $metric_name,
            'threshold' => $threshold,
            'current_value' => $current_value,
            'slope' => round($slope, 4),
            'status' => 'increasing',
            'days_until' => $days_until,
            'projected_date' => $projected_date,
            'message' => "At current rate, {$metric_name} will exceed {$threshold} in ~{$days_until} days ({$projected_date})",
            'confidence' => $this->calculateForecastConfidence($n, $days_lookback),
        ];
    }

    /**
     * Get all open findings for NAS
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     *
     * @return array List of findings with details
     */
    public function getOpenFindings(string $tenant_id, string $nas_id): array
    {
        return $this->findings->getFindings($tenant_id, $nas_id, 'open');
    }

    /**
     * Get recurring issues (happened multiple times)
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     *
     * @return array List of recurring findings
     */
    public function getRecurringIssues(string $tenant_id, string $nas_id): array
    {
        return $this->findings->getRecurringIssues($tenant_id, $nas_id, 2);
    }

    /**
     * Compare metrics before and after a fix
     *
     * @param string $tenant_id Tenant identifier for row-level security
     * @param string $nas_id NAS identifier
     * @param string $metric_name Metric to compare
     * @param string $fix_date Date when fix was applied (ISO 8601)
     * @param int $days_before Days of data before fix
     * @param int $days_after Days of data after fix
     *
     * @return array Before/after comparison
     */
    public function compareBeforeAfter(
        string $tenant_id,
        string $nas_id,
        string $metric_name,
        string $fix_date,
        int $days_before = 7,
        int $days_after = 7
    ): array {
        $before_start = date('Y-m-d', strtotime("-{$days_before} days", strtotime($fix_date)));
        $before_end = date('Y-m-d', strtotime('-1 day', strtotime($fix_date)));
        $after_start = $fix_date;
        $after_end = date('Y-m-d', strtotime("+{$days_after} days", strtotime($fix_date)));

        $before_data = $this->persistence->getMetricsRange(
            $tenant_id,
            $nas_id,
            $metric_name,
            $before_start,
            $before_end
        );

        $after_data = $this->persistence->getMetricsRange(
            $tenant_id,
            $nas_id,
            $metric_name,
            $after_start,
            $after_end
        );

        $before_values = array_map(fn($d) => $d['value'], $before_data);
        $after_values = array_map(fn($d) => $d['value'], $after_data);

        $before_avg = count($before_values) > 0 ? array_sum($before_values) / count($before_values) : 0;
        $after_avg = count($after_values) > 0 ? array_sum($after_values) / count($after_values) : 0;

        $improvement = $before_avg > 0
            ? round((($before_avg - $after_avg) / $before_avg) * 100, 2)
            : 0;

        return [
            'metric_name' => $metric_name,
            'fix_date' => $fix_date,
            'before' => [
                'period' => "{$before_start} to {$before_end}",
                'data_points' => count($before_data),
                'average' => round($before_avg, 2),
                'min' => count($before_values) > 0 ? min($before_values) : null,
                'max' => count($before_values) > 0 ? max($before_values) : null,
            ],
            'after' => [
                'period' => "{$after_start} to {$after_end}",
                'data_points' => count($after_data),
                'average' => round($after_avg, 2),
                'min' => count($after_values) > 0 ? min($after_values) : null,
                'max' => count($after_values) > 0 ? max($after_values) : null,
            ],
            'improvement_percent' => $improvement,
            'summary' => $improvement > 5
                ? "Fix was effective: {$improvement}% improvement"
                : ($improvement > -5
                    ? "No significant change"
                    : "Metric worsened by " . abs($improvement) . "%"),
        ];
    }

    /**
     * Format trend summary for display
     */
    private function formatTrendSummary(
        string $metric_name,
        string $trend,
        float $slope,
        float $current_value
    ): string {
        return match ($trend) {
            'increasing' => "{$metric_name} is increasing (slope: +{$slope}/sample, current: {$current_value})",
            'decreasing' => "{$metric_name} is decreasing (slope: {$slope}/sample, current: {$current_value})",
            'stable' => "{$metric_name} is stable (slope: {$slope}/sample, current: {$current_value})",
            default => "Unable to determine trend for {$metric_name}",
        };
    }

    /**
     * Calculate forecast confidence (higher with more data points)
     */
    private function calculateForecastConfidence(int $data_points, int $days_lookback): string
    {
        $ratio = $data_points / $days_lookback;

        if ($ratio >= 0.9) {
            return 'high';
        } elseif ($ratio >= 0.7) {
            return 'medium';
        } else {
            return 'low';
        }
    }

    /**
     * Log a message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[historical-analyzer] ' . $message);
        }
    }
}
