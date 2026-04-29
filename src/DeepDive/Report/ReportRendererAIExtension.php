<?php

declare(strict_types=1);

namespace App\DeepDive\Report;

/**
 * Report Renderer AI Extension Methods
 *
 * PURPOSE:
 * Mixin methods for rendering Phase 1 & 2 AI findings in HTML reports.
 * Add these methods to ReportRenderer class.
 *
 * SECTIONS:
 * 1. renderAISection() - Main AI findings section
 * 2. renderAnomaliesSection() - Phase 1 anomalies by subsystem
 * 3. renderCausalChainsSection() - Phase 2 causal chains
 * 4. renderRootCausesSection() - Root cause analysis with remediation
 * 5. renderRemediationRoadmap() - Step-by-step remediation steps
 *
 * STYLING:
 * Color-coded by severity: CRITICAL (red), HIGH (orange), MEDIUM (blue), LOW (green)
 * Markdown support in narratives
 * Responsive layout for desktop and mobile
 */

class ReportRendererAIExtension
{
    /**
     * Render AI findings section
     *
     * Main entry point for AI analysis results
     * Shows overview and drill-down sections
     *
     * @param array $aiFindings AI results from Phase 1 & 2
     *
     * @return string HTML content
     */
    public static function renderAISection(array $aiFindings): string
    {
        if (empty($aiFindings)) {
            return '';
        }

        $html = <<<'HTML'
<section class="ai-analysis" style="margin: 30px 0;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                padding: 20px; color: white; border-radius: 8px; margin-bottom: 20px;">
        <h2 style="margin: 0; color: white;">🤖 AI Anomaly & Root Cause Analysis</h2>
        <p style="margin: 10px 0 0 0; opacity: 0.9;">
            Automated detection of power supply, thermal, and hardware anomalies with root cause identification
        </p>
    </div>
HTML;

        // Process each bundle's findings
        foreach ($aiFindings as $bundleFindings) {
            if ($bundleFindings['status'] ?? null === 'error') {
                $html .= self::renderBundleError($bundleFindings);
                continue;
            }

            $bundleId = $bundleFindings['bundle_id'] ?? 'unknown';
            $overallRisk = $bundleFindings['overall_risk'] ?? 'LOW';

            // Bundle header
            $riskColor = self::getRiskColor($overallRisk);
            $html .= sprintf(
                '<div style="border-left: 4px solid %s; background: %s; padding: 15px; margin-bottom: 20px; border-radius: 4px;">',
                $riskColor,
                self::getRiskBackground($overallRisk)
            );

            $html .= sprintf(
                '<h3 style="margin: 0 0 10px 0;">Bundle: %s <span style="color: %s; font-weight: bold;">%s RISK</span></h3>',
                htmlspecialchars($bundleId),
                $riskColor,
                $overallRisk
            );

            // Summary stats
            $phase1 = $bundleFindings['phase1'] ?? [];
            $phase2 = $bundleFindings['phase2'] ?? [];
            $summary = $bundleFindings['summary'] ?? [];

            $html .= sprintf(
                '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; margin-bottom: 15px;">
                    <div style="background: white; padding: 10px; border-radius: 4px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #667eea;">%d</div>
                        <div style="font-size: 12px; color: #666;">Anomalies</div>
                    </div>
                    <div style="background: white; padding: 10px; border-radius: 4px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #667eea;">%d</div>
                        <div style="font-size: 12px; color: #666;">Chains</div>
                    </div>
                    <div style="background: white; padding: 10px; border-radius: 4px; text-align: center;">
                        <div style="font-size: 24px; font-weight: bold; color: #667eea;">%d</div>
                        <div style="font-size: 12px; color: #666;">Root Causes</div>
                    </div>
                    <div style="background: white; padding: 10px; border-radius: 4px; text-align: center;">
                        <div style="font-size: 14px; font-weight: bold; color: #667eea;">%d</div>
                        <div style="font-size: 12px; color: #666;">Tokens</div>
                    </div>
                </div>',
                $summary['anomaly_count'] ?? 0,
                $summary['chain_count'] ?? 0,
                $summary['root_cause_count'] ?? 0,
                $summary['token_usage'] ?? 0
            );

            // Render subsections
            if (!empty($phase1['findings'])) {
                $html .= self::renderAnomaliesSection($phase1['findings']);
            }

            if (!empty($phase2['chains'])) {
                $html .= self::renderCausalChainsSection($phase2['chains']);
            }

            if (!empty($phase2['root_causes'])) {
                $html .= self::renderRootCausesSection($phase2['root_causes']);
            }

            $html .= '</div>'; // Close bundle container
        }

        $html .= '</section>';
        return $html;
    }

    /**
     * Render anomalies from Phase 1
     *
     * @param array $findings Subsystem findings
     *
     * @return string HTML content
     */
    private static function renderAnomaliesSection(array $findings): string
    {
        $html = '<div style="margin: 15px 0;">';
        $html .= '<h4 style="margin: 10px 0;">📊 Phase 1: Anomalies Detected</h4>';

        foreach ($findings as $subsystem => $subsystemData) {
            if (empty($subsystemData['anomalies'])) {
                continue;
            }

            $anomalies = $subsystemData['anomalies'];
            $html .= sprintf('<div style="margin: 10px 0;"><strong>%s:</strong></div>', ucfirst($subsystem));
            $html .= '<table style="width: 100%; border-collapse: collapse; font-size: 13px;">';
            $html .= '<tr style="background: #f5f5f5;">';
            $html .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Type</th>';
            $html .= '<th style="padding: 8px; text-align: left; border-bottom: 1px solid #ddd;">Timestamp</th>';
            $html .= '<th style="padding: 8px; text-align: center; border-bottom: 1px solid #ddd;">Confidence</th>';
            $html .= '</tr>';

            foreach ($anomalies as $anomaly) {
                $confidence = floatval($anomaly['confidence'] ?? 0.5);
                $type = htmlspecialchars($anomaly['type'] ?? 'UNKNOWN');
                $timestamp = htmlspecialchars($anomaly['timestamp'] ?? '');

                $html .= sprintf(
                    '<tr style="border-bottom: 1px solid #eee;">
                        <td style="padding: 8px;">%s</td>
                        <td style="padding: 8px;">%s</td>
                        <td style="padding: 8px; text-align: center;">
                            <span style="background: #e8f4f8; padding: 3px 8px; border-radius: 3px;">
                                %.0f%%
                            </span>
                        </td>
                    </tr>',
                    $type,
                    $timestamp,
                    $confidence * 100
                );
            }

            $html .= '</table>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render causal chains from Phase 2
     *
     * @param array $chains Causal chains
     *
     * @return string HTML content
     */
    private static function renderCausalChainsSection(array $chains): string
    {
        if (empty($chains)) {
            return '';
        }

        $html = '<div style="margin: 15px 0;">';
        $html .= '<h4 style="margin: 10px 0;">⛓️ Phase 2: Causal Chains</h4>';

        foreach ($chains as $idx => $chain) {
            $type = htmlspecialchars($chain->type());
            $confidence = $chain->confidence();
            $severity = htmlspecialchars($chain->severity());
            $strength = $chain->calculateStrength();

            $color = self::getRiskColor($severity);

            $html .= sprintf(
                '<div style="border-left: 3px solid %s; background: #fafafa; padding: 12px; margin: 10px 0; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong style="color: %s;">%s</strong>
                            <span style="color: #999; font-size: 12px;">(%d events)</span>
                        </div>
                        <div style="text-align: right;">
                            <div style="font-size: 14px; font-weight: bold;">Confidence: %.0f%%</div>
                            <div style="font-size: 12px; color: #666;">Strength: %.2f/1.0</div>
                        </div>
                    </div>',
                $color,
                $color,
                $type,
                $chain->eventCount(),
                $confidence * 100,
                $strength
            );

            // Show cascade steps
            if (!empty($chain->intermediateEvents())) {
                $html .= '<div style="margin-top: 10px; font-size: 12px;">';
                $html .= '→ ' . htmlspecialchars($chain->rootEvent()['type']) . ' ';
                foreach ($chain->intermediateEvents() as $event) {
                    $html .= '→ ' . htmlspecialchars($event['anomaly']['type'] ?? '') . ' ';
                }
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render root cause analysis findings
     *
     * @param array $findings Root cause findings
     *
     * @return string HTML content
     */
    private static function renderRootCausesSection(array $findings): string
    {
        if (empty($findings)) {
            return '';
        }

        $html = '<div style="margin: 15px 0;">';
        $html .= '<h4 style="margin: 10px 0;">🔍 Root Cause Analysis</h4>';

        foreach ($findings as $finding) {
            $rootCause = htmlspecialchars($finding['root_cause'] ?? 'Unknown');
            $confidence = floatval($finding['confidence'] ?? 0.5);
            $severity = htmlspecialchars($finding['impact_severity'] ?? 'MEDIUM');
            $action = htmlspecialchars($finding['recommended_action'] ?? 'INVESTIGATE');

            $color = self::getRiskColor($severity);

            $html .= sprintf(
                '<div style="border-left: 3px solid %s; background: %s; padding: 12px; margin: 10px 0; border-radius: 4px;">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                        <div style="flex: 1;">
                            <div style="font-weight: bold; color: %s; margin-bottom: 5px;">%s</div>
                            <div style="color: #333; margin-bottom: 8px;">%s</div>
                            <div style="font-size: 12px; color: #666;">
                                <span style="display: inline-block; background: white; padding: 2px 6px; border-radius: 3px; margin-right: 10px;">
                                    Status: %s
                                </span>
                                <span style="display: inline-block; background: white; padding: 2px 6px; border-radius: 3px;">
                                    Action: <strong>%s</strong>
                                </span>
                            </div>
                        </div>
                        <div style="text-align: right; margin-left: 15px;">
                            <div style="font-size: 18px; font-weight: bold; color: %s;">%.0f%%</div>
                            <div style="font-size: 11px; color: #666;">Confidence</div>
                        </div>
                    </div>',
                $color,
                self::getRiskBackground($severity),
                $color,
                $severity,
                $rootCause,
                htmlspecialchars($finding['status'] ?? 'unknown'),
                $action,
                $color,
                $confidence * 100
            );

            // Show remediation steps if available
            if (!empty($finding['remediation_roadmap'])) {
                $html .= '<div style="margin-top: 10px; font-size: 12px;">';
                $html .= '<strong>Remediation Steps:</strong>';
                $html .= '<ol style="margin: 8px 0; padding-left: 20px;">';

                foreach (array_slice($finding['remediation_roadmap'], 0, 5) as $step) {
                    $downtime = $step['requires_downtime'] ? ' ⚠️' : '';
                    $html .= sprintf(
                        '<li>%s <em>(%s)%s</em></li>',
                        htmlspecialchars($step['step']),
                        htmlspecialchars($step['estimated_time']),
                        $downtime
                    );
                }

                if (count($finding['remediation_roadmap']) > 5) {
                    $html .= '<li><em>... and ' . (count($finding['remediation_roadmap']) - 5) . ' more steps</em></li>';
                }

                $html .= '</ol>';
                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * Render bundle error message
     *
     * @param array $bundleError Bundle error data
     *
     * @return string HTML content
     */
    private static function renderBundleError(array $bundleError): string
    {
        $bundleId = htmlspecialchars($bundleError['bundle_id'] ?? 'unknown');
        $error = htmlspecialchars($bundleError['error'] ?? 'Unknown error');

        return sprintf(
            '<div style="border-left: 4px solid #ef4444; background: #fee; padding: 12px; margin: 10px 0; border-radius: 4px;">
                <strong style="color: #ef4444;">Bundle %s:</strong> Analysis failed
                <div style="color: #666; font-size: 12px; margin-top: 5px;">%s</div>
            </div>',
            $bundleId,
            $error
        );
    }

    /**
     * Get color for risk level
     *
     * @param string $risk Risk level
     *
     * @return string Hex color
     */
    private static function getRiskColor(string $risk): string
    {
        return match(strtoupper($risk)) {
            'CRITICAL' => '#dc2626',
            'HIGH'     => '#f59e0b',
            'MEDIUM'   => '#3b82f6',
            'LOW'      => '#10b981',
            default    => '#6b7280',
        };
    }

    /**
     * Get background color for risk level
     *
     * @param string $risk Risk level
     *
     * @return string Hex color
     */
    private static function getRiskBackground(string $risk): string
    {
        return match(strtoupper($risk)) {
            'CRITICAL' => '#fef2f2',
            'HIGH'     => '#fffbeb',
            'MEDIUM'   => '#f0f9ff',
            'LOW'      => '#f0fdf4',
            default    => '#f9fafb',
        };
    }
}
