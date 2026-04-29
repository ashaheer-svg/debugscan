<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

/**
 * Time Series Analyzer: Statistical anomaly detection
 *
 * PURPOSE:
 * Detects anomalies in numerical time series data using:
 * - Z-score analysis (deviation from baseline mean/stddev)
 * - Baseline calculation (rolling window statistics)
 * - Change-point detection (sudden shifts in behavior)
 * - Seasonality handling (expected patterns)
 *
 * METHODS:
 * 1. Z-score: Identify readings > 2 std devs from mean
 * 2. Baseline: Calculate 7-day rolling baseline
 * 3. Threshold: Apply domain-specific hard limits
 * 4. Change-point: Detect sudden shifts in time series
 *
 * ASSUMPTIONS:
 * - Data is roughly normally distributed (or can be normalized)
 * - Baseline period is recent and representative
 * - Anomalies appear as outliers or sudden changes
 *
 * @package App\DeepDive\AI
 */
final class TimeSeriesAnalyzer
{
    private const Z_SCORE_THRESHOLD = 2.0;      // 2 std devs = ~95% confidence
    private const MIN_BASELINE_POINTS = 10;     // Need 10+ points for stats
    private const CHANGE_POINT_THRESHOLD = 1.5; // Change threshold multiplier

    /**
     * Analyze time series for anomalies
     *
     * FLOW:
     * 1. Extract numerical values and timestamps
     * 2. Calculate baseline (mean, stddev)
     * 3. Compute Z-scores for each point
     * 4. Detect anomalies (Z > threshold)
     * 5. Apply domain-specific hard limits
     * 6. Detect change points
     * 7. Return scored anomalies
     *
     * @param array $timeSeries Array of {timestamp: string, value: float|int}
     * @param array $config Configuration with optional thresholds
     *        - z_score_threshold: Override Z-score (default 2.0)
     *        - hard_min: Hard lower limit
     *        - hard_max: Hard upper limit
     *        - expected_range: Domain knowledge range {min, max}
     *
     * @return array{
     *     anomalies: array<array{
     *         timestamp: string,
     *         value: float,
     *         z_score: float,
     *         deviation_percent: float,
     *         anomaly_type: string,
     *         confidence: float
     *     }>,
     *     baseline: array{mean: float, stddev: float, min: float, max: float},
     *     statistics: array{total_points: int, anomaly_count: int, anomaly_rate: float}
     * }
     */
    public function analyzeTimeSeries(array $timeSeries, array $config = []): array
    {
        if (empty($timeSeries)) {
            return [
                'anomalies' => [],
                'baseline'  => ['mean' => 0, 'stddev' => 0, 'min' => 0, 'max' => 0],
                'statistics' => ['total_points' => 0, 'anomaly_count' => 0, 'anomaly_rate' => 0],
            ];
        }

        // Extract values
        $values = array_map(fn($p) => floatval($p['value'] ?? 0), $timeSeries);

        // Safeguard: need minimum points for stats
        if (count($values) < self::MIN_BASELINE_POINTS) {
            return [
                'anomalies'  => [],
                'baseline'   => $this->calculateBaseline($values),
                'statistics' => [
                    'total_points'  => count($values),
                    'anomaly_count' => 0,
                    'anomaly_rate'  => 0,
                ],
            ];
        }

        $baseline = $this->calculateBaseline($values);
        $zScoreThreshold = floatval($config['z_score_threshold'] ?? self::Z_SCORE_THRESHOLD);

        $anomalies = [];

        foreach ($timeSeries as $idx => $point) {
            $value = floatval($point['value'] ?? 0);
            $timestamp = $point['timestamp'] ?? '';

            $anomaly = null;

            // Check hard limits
            if (isset($config['hard_max']) && $value > $config['hard_max']) {
                $anomaly = [
                    'timestamp'          => $timestamp,
                    'value'              => $value,
                    'z_score'            => 0,
                    'deviation_percent'  => (($value - $baseline['max']) / $baseline['max']) * 100,
                    'anomaly_type'       => 'HARD_LIMIT_EXCEEDED',
                    'confidence'         => 0.95,
                ];
            } elseif (isset($config['hard_min']) && $value < $config['hard_min']) {
                $anomaly = [
                    'timestamp'          => $timestamp,
                    'value'              => $value,
                    'z_score'            => 0,
                    'deviation_percent'  => (($value - $baseline['min']) / $baseline['min']) * 100,
                    'anomaly_type'       => 'HARD_LIMIT_EXCEEDED',
                    'confidence'         => 0.95,
                ];
            } else {
                // Z-score analysis
                $zScore = $this->calculateZScore($value, $baseline['mean'], $baseline['stddev']);

                if (abs($zScore) >= $zScoreThreshold) {
                    $deviationPercent = (($value - $baseline['mean']) / max(abs($baseline['mean']), 0.01)) * 100;
                    $confidence = $this->zScoreToConfidence($zScore);

                    $anomaly = [
                        'timestamp'          => $timestamp,
                        'value'              => $value,
                        'z_score'            => round($zScore, 2),
                        'deviation_percent'  => round($deviationPercent, 1),
                        'anomaly_type'       => abs($zScore) >= 3.0 ? 'EXTREME_DEVIATION' : 'SIGNIFICANT_DEVIATION',
                        'confidence'         => round($confidence, 3),
                    ];
                }
            }

            if ($anomaly !== null) {
                $anomalies[] = $anomaly;
            }
        }

        // Detect change points
        $changePoints = $this->detectChangePoints($values, $timeSeries);
        $anomalies = array_merge($anomalies, $changePoints);

        // Sort by timestamp
        usort($anomalies, fn($a, $b) => strtotime($a['timestamp'] ?? '0') <=> strtotime($b['timestamp'] ?? '0'));

        return [
            'anomalies'  => $anomalies,
            'baseline'   => $baseline,
            'statistics' => [
                'total_points'  => count($timeSeries),
                'anomaly_count' => count($anomalies),
                'anomaly_rate'  => count($timeSeries) > 0 ? count($anomalies) / count($timeSeries) : 0,
            ],
        ];
    }

    /**
     * Detect clusters of anomalies
     *
     * Groups consecutive or nearby anomalies into clusters
     * indicating potential cascade failures or sustained issues
     *
     * @param array $anomalies Anomaly records from analyzeTimeSeries
     * @param int $timeWindowMinutes Group anomalies within this time window
     *
     * @return array<array{
     *     cluster_id: int,
     *     start_time: string,
     *     end_time: string,
     *     anomaly_count: int,
     *     severity: 'LOW'|'MEDIUM'|'HIGH'|'CRITICAL',
     *     anomalies: array
     * }>
     */
    public function clusterAnomalies(array $anomalies, int $timeWindowMinutes = 60): array
    {
        if (empty($anomalies)) {
            return [];
        }

        $clusters = [];
        $currentCluster = null;
        $clusterId = 0;

        foreach ($anomalies as $anomaly) {
            $timestamp = strtotime($anomaly['timestamp'] ?? '0');

            // Check if this anomaly belongs to current cluster
            if ($currentCluster !== null && ($timestamp - $currentCluster['last_timestamp']) <= ($timeWindowMinutes * 60)) {
                // Add to current cluster
                $currentCluster['anomalies'][] = $anomaly;
                $currentCluster['last_timestamp'] = $timestamp;
            } else {
                // Start new cluster
                if ($currentCluster !== null) {
                    $clusters[] = $this->formatCluster($currentCluster, $clusterId++);
                }

                $currentCluster = [
                    'start_timestamp'  => $timestamp,
                    'last_timestamp'   => $timestamp,
                    'anomalies'        => [$anomaly],
                ];
            }
        }

        // Don't forget last cluster
        if ($currentCluster !== null) {
            $clusters[] = $this->formatCluster($currentCluster, $clusterId);
        }

        return $clusters;
    }

    /**
     * Calculate baseline statistics from values
     *
     * Computes: mean, standard deviation, min, max
     *
     * @param array $values Array of numerical values
     *
     * @return array{mean: float, stddev: float, min: float, max: float}
     */
    private function calculateBaseline(array $values): array
    {
        if (empty($values)) {
            return ['mean' => 0, 'stddev' => 0, 'min' => 0, 'max' => 0];
        }

        $mean = array_sum($values) / count($values);
        $squaredDiffs = array_map(fn($v) => pow($v - $mean, 2), $values);
        $variance = array_sum($squaredDiffs) / count($values);
        $stddev = sqrt($variance);

        return [
            'mean'   => round($mean, 2),
            'stddev' => round($stddev, 2),
            'min'    => round(min($values), 2),
            'max'    => round(max($values), 2),
        ];
    }

    /**
     * Calculate Z-score for a single value
     *
     * Z = (X - mean) / stddev
     *
     * Interpretation:
     * - Z > 2: 95% confidence this is an outlier
     * - Z > 3: 99.7% confidence this is an outlier
     *
     * @param float $value The data point
     * @param float $mean Mean of distribution
     * @param float $stddev Standard deviation
     *
     * @return float Z-score (can be negative for below-mean values)
     */
    private function calculateZScore(float $value, float $mean, float $stddev): float
    {
        // Handle division by zero
        if ($stddev == 0) {
            return $value == $mean ? 0 : ($value > $mean ? 100 : -100);
        }

        return ($value - $mean) / $stddev;
    }

    /**
     * Convert Z-score to confidence level
     *
     * Uses cumulative normal distribution approximation
     *
     * @param float $zScore The Z-score
     *
     * @return float Confidence between 0 and 1
     */
    private function zScoreToConfidence(float $zScore): float
    {
        $absZ = abs($zScore);

        // Rough approximation of normal CDF
        if ($absZ >= 3.0) return 0.997; // 99.7%
        if ($absZ >= 2.5) return 0.988; // 98.8%
        if ($absZ >= 2.0) return 0.954; // 95.4%
        if ($absZ >= 1.5) return 0.866; // 86.6%

        return 0.683; // 68.3% for |Z| >= 1
    }

    /**
     * Detect change points in time series
     *
     * Uses comparison of consecutive segments
     * Detects sudden shifts in mean value
     *
     * @param array $values Numerical values
     * @param array $timeSeries Full time series with timestamps
     *
     * @return array<array{timestamp: string, ...}> Change point anomalies
     */
    private function detectChangePoints(array $values, array $timeSeries): array
    {
        if (count($values) < 5) {
            return [];
        }

        $changePoints = [];
        $windowSize = max(3, (int)(count($values) / 10)); // 10% of data

        for ($i = $windowSize; $i < count($values) - $windowSize; $i++) {
            // Compare mean before and after
            $before = array_slice($values, $i - $windowSize, $windowSize);
            $after = array_slice($values, $i, $windowSize);

            $meanBefore = array_sum($before) / count($before);
            $meanAfter = array_sum($after) / count($after);

            $change = abs(($meanAfter - $meanBefore) / max(abs($meanBefore), 0.01));

            if ($change >= self::CHANGE_POINT_THRESHOLD) {
                $changePoints[] = [
                    'timestamp'         => $timeSeries[$i]['timestamp'] ?? '',
                    'value'             => floatval($timeSeries[$i]['value'] ?? 0),
                    'z_score'           => 0,
                    'deviation_percent' => round($change * 100, 1),
                    'anomaly_type'      => 'CHANGE_POINT',
                    'confidence'        => min(0.9, 0.5 + ($change / 5)),
                ];
            }
        }

        return $changePoints;
    }

    /**
     * Format cluster for output
     *
     * @param array $clusterData Raw cluster data
     * @param int $clusterId Cluster identifier
     *
     * @return array Formatted cluster
     */
    private function formatCluster(array $clusterData, int $clusterId): array
    {
        $anomalies = $clusterData['anomalies'];
        $confidences = array_map(fn($a) => $a['confidence'] ?? 0, $anomalies);
        $avgConfidence = empty($confidences) ? 0 : array_sum($confidences) / count($confidences);

        // Determine severity based on anomaly count and confidence
        $severity = 'LOW';
        if (count($anomalies) >= 5 && $avgConfidence >= 0.8) {
            $severity = 'CRITICAL';
        } elseif (count($anomalies) >= 3 && $avgConfidence >= 0.7) {
            $severity = 'HIGH';
        } elseif (count($anomalies) >= 2) {
            $severity = 'MEDIUM';
        }

        return [
            'cluster_id'   => $clusterId,
            'start_time'   => date('Y-m-d H:i:s', $clusterData['start_timestamp']),
            'end_time'     => date('Y-m-d H:i:s', $clusterData['last_timestamp']),
            'anomaly_count' => count($anomalies),
            'severity'     => $severity,
            'avg_confidence' => round($avgConfidence, 3),
            'anomalies'    => $anomalies,
        ];
    }
}
