<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

/**
 * ReportRendererHistoricalExtension: Render historical analysis section
 *
 * PURPOSE:
 * Generates the "Historical Analysis" section of the report, showing:
 * - Recurring issues (problems that happen multiple times)
 * - Trend analysis (is this metric getting better or worse?)
 * - Forecasts (when will we hit the threshold?)
 * - Before/after analysis (did the fix work?)
 *
 * WORKFLOW:
 * Called from ReportRenderer.render() with historical data from HistoricalAnalyzer
 * Formats data into professional HTML cards and tables
 * Integrates seamlessly with existing report design
 */
final class ReportRendererHistoricalExtension
{
    /**
     * Render historical analysis section
     *
     * @param array $historical_data Analysis results from HistoricalAnalyzer:
     *   - recurring_issues: List of issues that occurred multiple times
     *   - trends: Metric trend analysis
     *   - forecasts: Threshold projections
     *   - before_after: Fix effectiveness comparison
     *
     * @return string HTML section ready for report embedding
     */
    public static function renderHistoricalSection(array $historical_data): string
    {
        $html = '<section class="historical-analysis">';
        $html .= '<h2 class="section-title">Historical Analysis & Trends</h2>';

        // Recurring issues block
        if (!empty($historical_data['recurring_issues'])) {
            $html .= self::renderRecurringIssues($historical_data['recurring_issues']);
        }

        // Trends block
        if (!empty($historical_data['trends'])) {
            $html .= self::renderTrends($historical_data['trends']);
        }

        // Forecasts block
        if (!empty($historical_data['forecasts'])) {
            $html .= self::renderForecasts($historical_data['forecasts']);
        }

        // Before/after block
        if (!empty($historical_data['before_after'])) {
            $html .= self::renderBeforeAfter($historical_data['before_after']);
        }

        $html .= '</section>';

        return $html;
    }

    /**
     * Render recurring issues (problems that happened multiple times)
     */
    private static function renderRecurringIssues(array $issues): string
    {
        $html = '<div class="hist-subsection recurring-issues">';
        $html .= '<h3>Recurring Issues</h3>';
        $html .= '<p class="subsection-desc">Problems that have occurred multiple times on this NAS:</p>';

        if (empty($issues)) {
            $html .= '<p class="no-data">No recurring issues detected.</p>';
        } else {
            $html .= '<table class="hist-table">';
            $html .= '<thead>';
            $html .= '<tr>';
            $html .= '<th>Component</th>';
            $html .= '<th>Issue Type</th>';
            $html .= '<th>Occurrences</th>';
            $html .= '<th>Severity</th>';
            $html .= '<th>First Detected</th>';
            $html .= '<th>Last Detected</th>';
            $html .= '</tr>';
            $html .= '</thead>';
            $html .= '<tbody>';

            foreach ($issues as $issue) {
                $component = htmlspecialchars((string)($issue['affected_component'] ?? '?'), ENT_QUOTES);
                $type = htmlspecialchars((string)($issue['issue_type'] ?? '?'), ENT_QUOTES);
                $occurrences = (int)($issue['occurrence_count'] ?? 0);
                $severity = htmlspecialchars((string)($issue['severity'] ?? 'unknown'), ENT_QUOTES);
                $first = self::formatDate($issue['first_detected'] ?? '');
                $last = self::formatDate($issue['last_detected'] ?? '');

                $severity_color = self::getSeverityColor($severity);

                $html .= '<tr>';
                $html .= "<td><code>{$component}</code></td>";
                $html .= "<td>{$type}</td>";
                $html .= "<td><strong>{$occurrences}x</strong></td>";
                $html .= "<td><span style=\"background:{$severity_color}\" class=\"severity-badge\">{$severity}</span></td>";
                $html .= "<td>{$first}</td>";
                $html .= "<td>{$last}</td>";
                $html .= '</tr>';
            }

            $html .= '</tbody>';
            $html .= '</table>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render trend analysis (is metric improving, degrading, or stable?)
     */
    private static function renderTrends(array $trends): string
    {
        $html = '<div class="hist-subsection trends">';
        $html .= '<h3>Metric Trends</h3>';
        $html .= '<p class="subsection-desc">Historical trend analysis showing if metrics are improving or degrading:</p>';

        if (empty($trends)) {
            $html .= '<p class="no-data">No trend data available.</p>';
        } else {
            $html .= '<div class="trend-cards">';

            foreach ($trends as $trend) {
                $metric = htmlspecialchars((string)($trend['metric_name'] ?? '?'), ENT_QUOTES);
                $direction = $trend['trend'] ?? 'stable';
                $current = (float)($trend['current_value'] ?? 0);
                $previous = (float)($trend['first_value'] ?? 0);
                $percent_change = (float)($trend['percent_change'] ?? 0);
                $slope = (float)($trend['slope'] ?? 0);
                $summary = htmlspecialchars((string)($trend['summary'] ?? ''), ENT_QUOTES);

                // Direction indicator
                $icon = match ($direction) {
                    'increasing' => '📈',
                    'decreasing' => '📉',
                    'stable' => '➡️',
                    default => '❓',
                };

                // Color based on direction
                $color = match ($direction) {
                    'increasing' => '#ea580c',   // Orange - getting worse
                    'decreasing' => '#16a34a',   // Green - improving
                    'stable' => '#0891b2',       // Blue - stable
                    default => '#666',
                };

                // Direction text
                $direction_text = match ($direction) {
                    'increasing' => 'Increasing',
                    'decreasing' => 'Decreasing',
                    'stable' => 'Stable',
                    default => 'Unknown',
                };

                $html .= '<div class="trend-card" style="border-left:4px solid ' . $color . '">';
                $html .= "<h4>{$icon} {$metric}</h4>";
                $html .= "<div class=\"trend-stat\">";
                $html .= "<span class=\"trend-direction\" style=\"color: {$color}\">{$direction_text}</span>";
                $html .= "<span class=\"trend-value\">Current: {$current}</span>";
                $html .= "</div>";

                if ($percent_change !== 0) {
                    $change_color = $percent_change > 0 ? '#ea580c' : '#16a34a';
                    $change_sign = $percent_change > 0 ? '+' : '';
                    $html .= "<div class=\"trend-change\" style=\"color: {$change_color}\">{$change_sign}{$percent_change}% change</div>";
                }

                $html .= "<p class=\"trend-summary\">{$summary}</p>";
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render forecast projections (when will threshold be exceeded?)
     */
    private static function renderForecasts(array $forecasts): string
    {
        $html = '<div class="hist-subsection forecasts">';
        $html .= '<h3>Threshold Forecasts</h3>';
        $html .= '<p class="subsection-desc">Projections showing when metrics will exceed warning or alarm thresholds:</p>';

        if (empty($forecasts)) {
            $html .= '<p class="no-data">No forecast data available.</p>';
        } else {
            $html .= '<div class="forecast-cards">';

            foreach ($forecasts as $forecast) {
                $metric = htmlspecialchars((string)($forecast['metric_name'] ?? '?'), ENT_QUOTES);
                $status = $forecast['status'] ?? 'unknown';
                $threshold = (float)($forecast['threshold'] ?? 0);
                $current = (float)($forecast['current_value'] ?? 0);
                $days_until = (int)($forecast['days_until'] ?? 0);
                $projected_date = htmlspecialchars((string)($forecast['projected_date'] ?? ''), ENT_QUOTES);
                $confidence = htmlspecialchars((string)($forecast['confidence'] ?? ''), ENT_QUOTES);
                $message = htmlspecialchars((string)($forecast['message'] ?? ''), ENT_QUOTES);

                // Status styling
                $status_color = match ($status) {
                    'exceeded' => '#dc2626',     // Red - already exceeded
                    'increasing' => '#ea580c',  // Orange - will exceed
                    'decreasing' => '#16a34a',  // Green - improving
                    default => '#666',
                };

                $status_icon = match ($status) {
                    'exceeded' => '🚨',
                    'increasing' => '⚠️',
                    'decreasing' => '✓',
                    default => '❓',
                };

                $status_text = match ($status) {
                    'exceeded' => 'EXCEEDED',
                    'increasing' => 'WILL EXCEED',
                    'decreasing' => 'IMPROVING',
                    'insufficient_data' => 'INSUFFICIENT DATA',
                    default => 'UNKNOWN',
                };

                $html .= '<div class="forecast-card" style="border-left:4px solid ' . $status_color . '">';
                $html .= "<h4>{$status_icon} {$metric}</h4>";
                $html .= "<div class=\"forecast-stat\">";
                $html .= "<span class=\"forecast-status\" style=\"color: {$status_color}; font-weight: bold;\">{$status_text}</span>";
                $html .= "<span class=\"forecast-value\">Current: {$current} / Threshold: {$threshold}</span>";
                $html .= "</div>";

                if ($status === 'increasing') {
                    $html .= "<div class=\"forecast-projection\">";
                    $html .= "Projected to exceed on: <strong>{$projected_date}</strong> ({$days_until} days)";
                    if ($confidence) {
                        $html .= " — Confidence: <strong>{$confidence}</strong>";
                    }
                    $html .= "</div>";
                }

                $html .= "<p class=\"forecast-message\">{$message}</p>";
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render before/after comparison (was the fix effective?)
     */
    private static function renderBeforeAfter(array $comparisons): string
    {
        $html = '<div class="hist-subsection before-after">';
        $html .= '<h3>Fix Effectiveness Analysis</h3>';
        $html .= '<p class="subsection-desc">Comparing metrics before and after applied fixes:</p>';

        if (empty($comparisons)) {
            $html .= '<p class="no-data">No before/after comparison data available.</p>';
        } else {
            $html .= '<div class="ba-cards">';

            foreach ($comparisons as $ba) {
                $metric = htmlspecialchars((string)($ba['metric_name'] ?? '?'), ENT_QUOTES);
                $fix_date = self::formatDate($ba['fix_date'] ?? '');
                $before_avg = (float)($ba['before']['average'] ?? 0);
                $after_avg = (float)($ba['after']['average'] ?? 0);
                $improvement = (float)($ba['improvement_percent'] ?? 0);
                $summary = htmlspecialchars((string)($ba['summary'] ?? ''), ENT_QUOTES);

                // Improvement color
                $improvement_color = match (true) {
                    $improvement > 5 => '#16a34a',     // Green - significant improvement
                    $improvement > -5 => '#ca8a04',    // Yellow - minimal change
                    default => '#ea580c',              // Orange - worsened
                };

                // Improvement icon
                $improvement_icon = match (true) {
                    $improvement > 5 => '✓',
                    $improvement > -5 => '➡️',
                    default => '❌',
                };

                $html .= '<div class="ba-card">';
                $html .= "<h4>{$metric}</h4>";
                $html .= "<div class=\"ba-date\">Fix applied: {$fix_date}</div>";
                $html .= '<div class="ba-comparison">';
                $html .= "<div class=\"ba-column\">";
                $html .= "<div class=\"ba-label\">Before</div>";
                $html .= "<div class=\"ba-value\">{$before_avg}</div>";
                $html .= "<div class=\"ba-range\">Min: " . ($ba['before']['min'] ?? 'N/A') . " | Max: " . ($ba['before']['max'] ?? 'N/A') . "</div>";
                $html .= "</div>";
                $html .= "<div class=\"ba-arrow\">→</div>";
                $html .= "<div class=\"ba-column\">";
                $html .= "<div class=\"ba-label\">After</div>";
                $html .= "<div class=\"ba-value\">{$after_avg}</div>";
                $html .= "<div class=\"ba-range\">Min: " . ($ba['after']['min'] ?? 'N/A') . " | Max: " . ($ba['after']['max'] ?? 'N/A') . "</div>";
                $html .= "</div>";
                $html .= '</div>';
                $html .= "<div class=\"ba-improvement\" style=\"color: {$improvement_color}\">";
                $html .= "{$improvement_icon} Improvement: {$improvement}%";
                $html .= "</div>";
                $html .= "<p class=\"ba-summary\">{$summary}</p>";
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Get color for severity level
     */
    private static function getSeverityColor(string $severity): string
    {
        return match ($severity) {
            'alarm' => '#dc2626',     // Red
            'warning' => '#ea580c',   // Orange
            'info' => '#0891b2',      // Blue
            'normal' => '#16a34a',    // Green
            default => '#666',
        };
    }

    /**
     * Format date/timestamp for display
     */
    private static function formatDate(?string $date): string
    {
        if (!$date) {
            return 'Unknown';
        }

        try {
            $dt = new \DateTime($date);
            return $dt->format('Y-m-d H:i');
        } catch (\Throwable) {
            return htmlspecialchars($date, ENT_QUOTES);
        }
    }
}
