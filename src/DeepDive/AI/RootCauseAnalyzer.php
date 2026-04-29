<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

use Psr\Log\LoggerInterface;

/**
 * Root Cause Analyzer: Analyze causal chains and generate remediation
 *
 * PURPOSE:
 * Takes causal chains from EventCorrelator and produces:
 * - Root cause analysis with confidence scoring
 * - Contributing factors breakdown
 * - Cascade effect explanation
 * - Actionable remediation steps
 * - Impact assessment
 * - Narrative suitable for reports
 *
 * OUTPUTS:
 * - Root cause identification with evidence
 * - Contributing factor analysis (why it happened?)
 * - Cascade analysis (what did it cause?)
 * - Remediation roadmap (how to fix it?)
 * - Impact statement (what did it affect?)
 * - Narrative (human-readable explanation)
 *
 * @package App\DeepDive\AI
 */
final class RootCauseAnalyzer
{
    private ?LoggerInterface $logger;

    // Remediation knowledge base
    private const REMEDIATION_KNOWLEDGE = [
        CausalChain::TYPE_PSU_FAILURE => [
            'root_cause'  => 'Power Supply failure or aged component reached end of life',
            'factors'     => [
                'Device age/hours of operation',
                'Operating temperature history',
                'Load profile and duty cycle',
                'Recent environmental changes',
            ],
            'cascade'     => [
                'Voltage instability → CPU throttling',
                'Insufficient power delivery → system slowdown',
                'Thermal stress on adjacent components',
                'Cascading failures in multi-PSU systems',
            ],
            'impact'      => [
                'CRITICAL: System reliability compromised',
                'HIGH: Data at risk during power events',
                'MEDIUM: Performance degradation ongoing',
            ],
            'remediation' => [
                [
                    'step' => 'Verify PSU status with BMC sensors',
                    'priority' => 1,
                    'impact' => 'Confirm failure before ordering',
                ],
                [
                    'step' => 'Order replacement PSU (matching model/spec)',
                    'priority' => 2,
                    'impact' => 'Obtain correct parts to avoid delays',
                ],
                [
                    'step' => 'Plan maintenance window (schedule downtime)',
                    'priority' => 3,
                    'impact' => 'Minimize service impact',
                ],
                [
                    'step' => 'Replace PSU and verify voltage readings',
                    'priority' => 4,
                    'impact' => 'Restore full system reliability',
                ],
                [
                    'step' => 'Monitor voltages for 72 hours post-repair',
                    'priority' => 5,
                    'impact' => 'Verify stability and catch secondary issues',
                ],
            ],
        ],

        CausalChain::TYPE_THERMAL_CASCADE => [
            'root_cause'  => 'Thermal subsystem failure (fan, heatsink, or environmental)',
            'factors'     => [
                'Fan bearing wear or mechanical failure',
                'Heatsink dust accumulation',
                'Thermal paste degradation',
                'Elevated ambient temperature',
                'Airflow obstruction',
            ],
            'cascade'     => [
                'Temperature rise → CPU throttling',
                'Throttling → performance degradation',
                'Sustained heat → thermal warnings',
                'Emergency shutdown if temp exceeds limits',
            ],
            'impact'      => [
                'CRITICAL: Risk of automatic shutdown',
                'HIGH: Significant performance loss',
                'MEDIUM: Reduced component lifespan',
            ],
            'remediation' => [
                [
                    'step' => 'Verify current temperatures (monitor for 10+ minutes)',
                    'priority' => 1,
                    'impact' => 'Confirm heating trend vs transient spike',
                ],
                [
                    'step' => 'Check fan status and rotation speed',
                    'priority' => 2,
                    'impact' => 'Identify if fan is failing',
                ],
                [
                    'step' => 'Clean heatsinks and fans (compressed air)',
                    'priority' => 3,
                    'impact' => 'Often resolves dust-related thermal issues',
                ],
                [
                    'step' => 'Verify airflow paths are unobstructed',
                    'priority' => 4,
                    'impact' => 'Restore proper cooling',
                ],
                [
                    'step' => 'Replace fan if bearing failure detected',
                    'priority' => 5,
                    'impact' => 'Prevent thermal runaway',
                ],
            ],
        ],

        CausalChain::TYPE_DISK_DEGRADATION => [
            'root_cause'  => 'Disk degradation (aging, SMART errors, or sector reallocation)',
            'factors'     => [
                'Disk age and total power-on hours',
                'Read error rate exceeding thresholds',
                'Sector reallocation pool depletion',
                'Uncorrectable error count',
                'Recent vibration or mechanical shock',
            ],
            'cascade'     => [
                'Slow read performance → system slowness',
                'Increasing error rate → RAID rebuild stress',
                'Sector reallocation → disk failure risk',
                'Multi-disk failure → data loss potential',
            ],
            'impact'      => [
                'CRITICAL: Data loss risk if multiple disks affected',
                'HIGH: Performance severe, RAID at risk',
                'MEDIUM: Gradual degradation, window to repair',
            ],
            'remediation' => [
                [
                    'step' => 'Run full SMART diagnostic on affected disk',
                    'priority' => 1,
                    'impact' => 'Get detailed failure prediction',
                ],
                [
                    'step' => 'Check disk replacement warranty status',
                    'priority' => 2,
                    'impact' => 'Order warranty replacement if eligible',
                ],
                [
                    'step' => 'Verify RAID status and redundancy',
                    'priority' => 3,
                    'impact' => 'Ensure data protection intact',
                ],
                [
                    'step' => 'Order replacement disk (pre-staging)',
                    'priority' => 4,
                    'impact' => 'Have parts ready for quick replacement',
                ],
                [
                    'step' => 'Replace disk during maintenance window',
                    'priority' => 5,
                    'impact' => 'Restore full capacity and redundancy',
                ],
                [
                    'step' => 'Monitor RAID rebuild progress and temps',
                    'priority' => 6,
                    'impact' => 'Catch issues during critical rebuild phase',
                ],
            ],
        ],

        CausalChain::TYPE_POWER_DELIVERY => [
            'root_cause'  => 'Power delivery system instability (PSU aging, loose connections, or load issues)',
            'factors'     => [
                'PSU output ripple exceeding spec',
                'Loose power connector contacts',
                'Degraded power distribution traces',
                'Excessive system power draw',
                'Ambient temperature effects',
            ],
            'cascade'     => [
                'Voltage fluctuations → CPU brown-outs',
                'Brown-outs → CPU throttling or reset',
                'Repeated resets → system instability',
                'Cumulative strain → component wear',
            ],
            'impact'      => [
                'CRITICAL: Intermittent failures, hard to diagnose',
                'HIGH: System unreliability',
                'MEDIUM: Component accelerated aging',
            ],
            'remediation' => [
                [
                    'step' => 'Measure voltage rails with oscilloscope/meter',
                    'priority' => 1,
                    'impact' => 'Determine if ripple is within spec',
                ],
                [
                    'step' => 'Check all power connections (reseat cables)',
                    'priority' => 2,
                    'impact' => 'Often fixes contact-related issues',
                ],
                [
                    'step' => 'Clean connector contacts with electronics cleaner',
                    'priority' => 3,
                    'impact' => 'Remove oxidation that causes instability',
                ],
                [
                    'step' => 'Verify load is within PSU rating',
                    'priority' => 4,
                    'impact' => 'Ensure PSU has headroom',
                ],
                [
                    'step' => 'Replace PSU if voltage out of spec',
                    'priority' => 5,
                    'impact' => 'Restore stable power delivery',
                ],
            ],
        ],

        CausalChain::TYPE_HARDWARE_FAILURE => [
            'root_cause'  => 'Hardware component failure (RAID controller, memory, or other)',
            'factors'     => [
                'Component age and usage patterns',
                'Thermal stress history',
                'Power supply quality',
                'Manufacturing defects',
                'Firmware issues',
            ],
            'cascade'     => [
                'Component failure → service degradation',
                'Lost redundancy → increased risk',
                'Performance impact → user complaints',
                'Potential data loss if unmitigated',
            ],
            'impact'      => [
                'CRITICAL: System down or data at risk',
                'HIGH: Service degradation',
                'MEDIUM: Performance impact',
            ],
            'remediation' => [
                [
                    'step' => 'Identify specific failed component',
                    'priority' => 1,
                    'impact' => 'Narrow down replacement parts',
                ],
                [
                    'step' => 'Check warranty and support options',
                    'priority' => 2,
                    'impact' => 'Leverage RMA/replacement programs',
                ],
                [
                    'step' => 'Order replacement component',
                    'priority' => 3,
                    'impact' => 'Prepare for replacement',
                ],
                [
                    'step' => 'Schedule replacement during maintenance',
                    'priority' => 4,
                    'impact' => 'Minimize service impact',
                ],
                [
                    'step' => 'Perform post-repair verification testing',
                    'priority' => 5,
                    'impact' => 'Ensure fix is successful',
                ],
            ],
        ],
    ];

    /**
     * Initialize analyzer
     *
     * @param ?LoggerInterface $logger Optional PSR-3 logger
     */
    public function __construct(?LoggerInterface $logger = null)
    {
        $this->logger = $logger;
    }

    /**
     * Analyze causal chain and produce root cause findings
     *
     * FLOW:
     * 1. Validate chain structure
     * 2. Extract root cause from chain
     * 3. Analyze contributing factors
     * 4. Explain cascade mechanism
     * 5. Generate remediation roadmap
     * 6. Calculate impact severity
     * 7. Build human-readable narrative
     * 8. Score confidence
     *
     * @param CausalChain $chain Causal chain from EventCorrelator
     *
     * @return array{
     *     root_cause: string,
     *     confidence: float,
     *     status: string,
     *     contributing_factors: array,
     *     cascade_explanation: string,
     *     cascade_steps: array,
     *     impact_severity: string,
     *     impact_statement: string,
     *     remediation_roadmap: array,
     *     narrative: string,
     *     recommended_action: string
     * }
     */
    public function analyzeChain(CausalChain $chain): array
    {
        $this->log('info', "Analyzing chain: {$chain->type()}");

        // Get knowledge base for this chain type
        $knowledge = self::REMEDIATION_KNOWLEDGE[$chain->type()] ??
                    self::REMEDIATION_KNOWLEDGE[CausalChain::TYPE_UNKNOWN] ?? [];

        // === Extract root cause ===
        $rootCauseAnalysis = $this->analyzeRootCause($chain, $knowledge);

        // === Analyze contributing factors ===
        $factorsAnalysis = $this->analyzeContributingFactors($chain, $knowledge);

        // === Explain cascade ===
        $cascadeAnalysis = $this->analyzeCascade($chain, $knowledge);

        // === Assess impact ===
        $impactAnalysis = $this->assessImpact($chain, $knowledge);

        // === Generate remediation ===
        $remediationAnalysis = $this->generateRemediation($chain, $knowledge);

        // === Build narrative ===
        $narrative = $this->buildNarrative($chain, $rootCauseAnalysis, $cascadeAnalysis, $impactAnalysis);

        // === Score overall confidence ===
        $confidence = $this->scoreConfidence($chain, $rootCauseAnalysis, $cascadeAnalysis);

        $this->log('info', sprintf('Analysis complete: confidence=%.2f, action=%s',
            $confidence, $chain->recommendedAction()));

        return [
            'root_cause'           => $rootCauseAnalysis['statement'],
            'confidence'           => $confidence,
            'status'               => $chain->status(),
            'contributing_factors' => $factorsAnalysis,
            'cascade_explanation'  => $cascadeAnalysis['summary'],
            'cascade_steps'        => $cascadeAnalysis['steps'],
            'impact_severity'      => $impactAnalysis['severity'],
            'impact_statement'     => $impactAnalysis['statement'],
            'remediation_roadmap'  => $remediationAnalysis,
            'narrative'            => $narrative,
            'recommended_action'   => $chain->recommendedAction(),
        ];
    }

    /**
     * Analyze root cause from chain
     *
     * @param CausalChain $chain Causal chain
     * @param array $knowledge Knowledge base
     *
     * @return array Root cause analysis
     */
    private function analyzeRootCause(CausalChain $chain, array $knowledge): array
    {
        $rootEvent = $chain->rootEvent();

        $statement = $knowledge['root_cause'] ?? 'Unknown root cause: ' . ($rootEvent['type'] ?? '');

        // Check for contributing factors that actually preceded root
        $precedingFactors = [];
        $factors = $chain->contributingFactors();
        foreach ($factors as $factor) {
            $factorTime = strtotime($factor['anomaly']['timestamp'] ?? '0') ?: 0;
            $rootTime = strtotime($rootEvent['timestamp'] ?? '0') ?: 0;

            if ($factorTime < $rootTime) {
                $precedingFactors[] = $factor['anomaly']['type'];
            }
        }

        if (!empty($precedingFactors)) {
            $statement .= ' (Preceded by: ' . implode(', ', $precedingFactors) . ')';
        }

        return [
            'statement'     => $statement,
            'event_type'    => $rootEvent['type'],
            'confidence'    => floatval($rootEvent['confidence'] ?? 0.7),
            'timestamp'     => $rootEvent['timestamp'],
        ];
    }

    /**
     * Analyze contributing factors
     *
     * @param CausalChain $chain Causal chain
     * @param array $knowledge Knowledge base
     *
     * @return array Contributing factors analysis
     */
    private function analyzeContributingFactors(CausalChain $chain, array $knowledge): array
    {
        $factors = [];
        $knownFactors = $knowledge['factors'] ?? [];

        foreach ($chain->contributingFactors() as $factor) {
            $factors[] = [
                'anomaly_type'   => $factor['anomaly']['type'],
                'relationship'   => $factor['relationship'],
                'timestamp'      => $factor['anomaly']['timestamp'],
                'message'        => $factor['anomaly']['message'] ?? '',
            ];
        }

        return [
            'detected_factors'   => $factors,
            'known_risk_factors' => $knownFactors,
            'assessment'         => count($factors) > 0
                ? sprintf('%d factors detected', count($factors))
                : 'No preceding factors (primary failure)',
        ];
    }

    /**
     * Analyze cascade mechanism
     *
     * @param CausalChain $chain Causal chain
     * @param array $knowledge Knowledge base
     *
     * @return array Cascade analysis
     */
    private function analyzeCascade(CausalChain $chain, array $knowledge): array
    {
        $intermediates = $chain->intermediateEvents();
        $symptoms = $chain->observableSymptoms();

        $steps = [];
        foreach ($intermediates as $idx => $event) {
            $steps[] = sprintf(
                '[%s] %s (after %.0f sec)',
                $event['anomaly']['type'],
                $event['anomaly']['message'] ?? '',
                $event['delay_seconds'] ?? 0
            );
        }

        foreach ($symptoms as $symptom) {
            $steps[] = 'SYMPTOM: ' . ($symptom['impact'] ?? $symptom['anomaly']['message'] ?? '');
        }

        $knownCascade = $knowledge['cascade'] ?? [];
        $summary = !empty($steps)
            ? sprintf('Cascade detected: %d intermediate events, %d symptoms',
                count($intermediates), count($symptoms))
            : 'No cascade detected (direct manifestation)';

        return [
            'summary'         => $summary,
            'steps'           => $steps,
            'known_patterns'  => $knownCascade,
            'step_count'      => count($steps),
        ];
    }

    /**
     * Assess impact severity
     *
     * @param CausalChain $chain Causal chain
     * @param array $knowledge Knowledge base
     *
     * @return array Impact assessment
     */
    private function assessImpact(CausalChain $chain, array $knowledge): array
    {
        $severity = $chain->severity();

        $impacts = [];
        $knownImpacts = $knowledge['impact'] ?? [];

        // Match severity to known impacts
        foreach ($knownImpacts as $impact) {
            if (str_starts_with($impact, strtoupper($severity))) {
                $impacts[] = $impact;
            }
        }

        if (empty($impacts)) {
            // Generate generic impact statement
            $impacts[] = match($severity) {
                CausalChain::SEVERITY_CRITICAL => 'CRITICAL: Service disruption or data loss risk',
                CausalChain::SEVERITY_HIGH => 'HIGH: Significant degradation or failure risk',
                CausalChain::SEVERITY_MEDIUM => 'MEDIUM: Operational impact',
                default => 'LOW: Minimal impact',
            };
        }

        return [
            'severity'   => $severity,
            'statement'  => implode('. ', $impacts),
            'impacts'    => $impacts,
        ];
    }

    /**
     * Generate remediation roadmap
     *
     * @param CausalChain $chain Causal chain
     * @param array $knowledge Knowledge base
     *
     * @return array Remediation steps
     */
    private function generateRemediation(CausalChain $chain, array $knowledge): array
    {
        $roadmap = [];
        $knownSteps = $knowledge['remediation'] ?? [];

        // Sort by priority
        usort($knownSteps, fn($a, $b) => ($a['priority'] ?? 999) <=> ($b['priority'] ?? 999));

        foreach ($knownSteps as $idx => $step) {
            $roadmap[] = [
                'priority'       => $idx + 1,
                'step'           => $step['step'],
                'estimated_time' => $this->estimateTime($step['step']),
                'impact'         => $step['impact'],
                'requires_downtime' => $this->requiresDowntime($step['step']),
            ];
        }

        return $roadmap;
    }

    /**
     * Build human-readable narrative
     *
     * @param CausalChain $chain Causal chain
     * @param array $rootAnalysis Root cause analysis
     * @param array $cascadeAnalysis Cascade analysis
     * @param array $impactAnalysis Impact analysis
     *
     * @return string Narrative text
     */
    private function buildNarrative(
        CausalChain $chain,
        array $rootAnalysis,
        array $cascadeAnalysis,
        array $impactAnalysis
    ): string {
        $lines = [];

        $lines[] = "## Root Cause Analysis";
        $lines[] = "";
        $lines[] = "**Root Cause**: " . $rootAnalysis['statement'];
        $lines[] = "";

        if (!empty($cascadeAnalysis['steps'])) {
            $lines[] = "**Cascade Mechanism**:";
            $lines[] = "";
            foreach ($cascadeAnalysis['steps'] as $step) {
                $lines[] = "- " . $step;
            }
            $lines[] = "";
        }

        $lines[] = "**Severity**: " . $impactAnalysis['severity'];
        $lines[] = "";
        $lines[] = "**Impact**: " . $impactAnalysis['statement'];
        $lines[] = "";

        $lines[] = "**Status**: " . ucfirst($chain->status());
        $lines[] = "**Confidence**: " . sprintf('%.1f%%', $chain->confidence() * 100);
        $lines[] = "";

        $lines[] = "**Recommended Action**: " . $chain->recommendedAction();

        return implode("\n", $lines);
    }

    /**
     * Score overall confidence in analysis
     *
     * @param CausalChain $chain Causal chain
     * @param array $rootAnalysis Root cause analysis
     * @param array $cascadeAnalysis Cascade analysis
     *
     * @return float Confidence 0.0-1.0
     */
    private function scoreConfidence(
        CausalChain $chain,
        array $rootAnalysis,
        array $cascadeAnalysis
    ): float
    {
        $factors = [];

        // Root cause confidence (40%)
        $factors[] = ['weight' => 0.40, 'score' => $rootAnalysis['confidence']];

        // Chain strength (30%)
        $factors[] = ['weight' => 0.30, 'score' => $chain->calculateStrength()];

        // Cascade coherence (20%)
        $cascadeScore = !empty($cascadeAnalysis['steps']) ? 0.9 : 0.6;
        $factors[] = ['weight' => 0.20, 'score' => $cascadeScore];

        // Chain confidence setting (10%)
        $factors[] = ['weight' => 0.10, 'score' => $chain->confidence()];

        // Weighted average
        $weighted = 0;
        foreach ($factors as $factor) {
            $weighted += $factor['weight'] * $factor['score'];
        }

        return min(0.99, max(0.40, $weighted));
    }

    /**
     * Estimate time to complete remediation step
     *
     * @param string $step Step description
     *
     * @return string Estimated time
     */
    private function estimateTime(string $step): string
    {
        if (stripos($step, 'order') !== false) return '1-3 days';
        if (stripos($step, 'schedule') !== false) return 'variable';
        if (stripos($step, 'replace') !== false) return '30-60 min';
        if (stripos($step, 'verify') !== false) return '5-15 min';
        if (stripos($step, 'monitor') !== false) return '72 hours';
        if (stripos($step, 'measure') !== false) return '10-20 min';
        if (stripos($step, 'check') !== false) return '5-10 min';
        if (stripos($step, 'clean') !== false) return '15-30 min';

        return 'unknown';
    }

    /**
     * Determine if step requires downtime
     *
     * @param string $step Step description
     *
     * @return bool True if requires downtime
     */
    private function requiresDowntime(string $step): bool
    {
        $downtime_keywords = ['replace', 'reseat', 'maintenance window', 'shutdown'];

        foreach ($downtime_keywords as $keyword) {
            if (stripos($step, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log a message
     *
     * @param string $level Log level
     * @param string $message Message
     */
    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, '[root-cause-analyzer] ' . $message);
        }
    }
}
