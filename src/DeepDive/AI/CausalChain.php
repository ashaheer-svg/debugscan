<?php

declare(strict_types=1);

namespace App\DeepDive\AI;

/**
 * Causal Chain: Data structure for event sequences and root causes
 *
 * PURPOSE:
 * Represents a chain of related events (anomalies) that form a causal sequence.
 * Links triggering events → contributing factors → observable symptoms.
 *
 * STRUCTURE:
 * Root Cause Event
 *   ↓
 * Contributing Factor 1, 2, N
 *   ↓
 * Intermediate Events (cascade)
 *   ↓
 * Observable Symptoms (end of chain)
 *
 * EXAMPLE - Silent PSU Failure Chain:
 * [Root] PSU voltage instability (11.8V)
 *   ↓ (contributes to)
 * [Factor] Insufficient power delivery to CPU
 *   ↓ (triggers)
 * [Intermediate] CPU throttling + thermal stress
 *   ↓ (manifests as)
 * [Symptom] System slowness, thermal warnings
 *
 * @package App\DeepDive\AI
 */
final class CausalChain
{
    // Chain severity levels
    public const SEVERITY_CRITICAL = 'CRITICAL';
    public const SEVERITY_HIGH = 'HIGH';
    public const SEVERITY_MEDIUM = 'MEDIUM';
    public const SEVERITY_LOW = 'LOW';

    // Chain types
    public const TYPE_PSU_FAILURE = 'psu_failure';
    public const TYPE_THERMAL_CASCADE = 'thermal_cascade';
    public const TYPE_DISK_DEGRADATION = 'disk_degradation';
    public const TYPE_POWER_DELIVERY = 'power_delivery';
    public const TYPE_HARDWARE_FAILURE = 'hardware_failure';
    public const TYPE_UNKNOWN = 'unknown_pattern';

    private string $chainId;
    private string $type;
    private string $severity;
    private float $confidence;

    private array $rootEvent;           // The triggering event
    private array $contributingFactors; // Events that enabled/worsened root
    private array $intermediateEvents;  // Cascade events
    private array $observableSymptoms;  // End-state manifestations

    private string $narrative;          // Human-readable description
    private array $remediationSteps;    // How to fix it
    private string $status;             // Confirmed, likely, speculative

    /**
     * Create a causal chain
     *
     * @param string $chainId Unique identifier
     * @param string $type Chain type (PSU_FAILURE, etc)
     * @param array $rootEvent The triggering anomaly
     */
    public function __construct(
        string $chainId,
        string $type,
        array $rootEvent
    ) {
        $this->chainId = $chainId;
        $this->type = $type;
        $this->rootEvent = $rootEvent;
        $this->severity = self::SEVERITY_MEDIUM;
        $this->confidence = 0.5;
        $this->contributingFactors = [];
        $this->intermediateEvents = [];
        $this->observableSymptoms = [];
        $this->narrative = '';
        $this->remediationSteps = [];
        $this->status = 'speculative';
    }

    // ========================================================================
    // Builder Methods
    // ========================================================================

    /**
     * Add a contributing factor (prerequisite or enabler)
     *
     * Contributing factors are issues that enabled the root cause:
     * - Aged PSU before voltage instability
     * - High ambient temp before thermal failure
     * - Pre-existing disk errors before degradation
     *
     * @param array $anomaly The contributing anomaly
     * @param string $relationship How it relates (e.g., 'precedes', 'enables')
     *
     * @return self
     */
    public function addContributingFactor(array $anomaly, string $relationship = 'enables'): self
    {
        $this->contributingFactors[] = [
            'anomaly'      => $anomaly,
            'relationship' => $relationship,
            'weight'       => 0.5, // Will be refined by analyzer
        ];
        return $this;
    }

    /**
     * Add an intermediate event (cascade step)
     *
     * Intermediate events form the cascade between root and symptoms:
     * PSU voltage drop → CPU voltage sag → CPU throttling → thermal stress
     *
     * @param array $anomaly The intermediate anomaly
     * @param int $sequenceOrder Order in cascade (1, 2, 3...)
     * @param float $delaySeconds Seconds after root event
     *
     * @return self
     */
    public function addIntermediateEvent(array $anomaly, int $sequenceOrder = 0, float $delaySeconds = 0.0): self
    {
        $this->intermediateEvents[] = [
            'anomaly'         => $anomaly,
            'sequence_order'  => $sequenceOrder ?: count($this->intermediateEvents) + 1,
            'delay_seconds'   => $delaySeconds,
        ];
        return $this;
    }

    /**
     * Add an observable symptom (end of chain)
     *
     * Symptoms are what operators/users experience:
     * - System slowness
     * - Thermal warnings
     * - Service unavailability
     * - Data corruption
     *
     * @param array $anomaly The symptom anomaly
     * @param string $impact User-visible impact
     *
     * @return self
     */
    public function addObservableSymptom(array $anomaly, string $impact = ''): self
    {
        $this->observableSymptoms[] = [
            'anomaly' => $anomaly,
            'impact'  => $impact,
        ];
        return $this;
    }

    /**
     * Set chain severity
     *
     * CRITICAL: Service down, data at risk
     * HIGH: Significant degradation, repair urgent
     * MEDIUM: Minor degradation, repair recommended
     * LOW: Cosmetic, repair optional
     *
     * @param string $severity One of SEVERITY_* constants
     *
     * @return self
     */
    public function setSeverity(string $severity): self
    {
        if (!in_array($severity, [
            self::SEVERITY_CRITICAL,
            self::SEVERITY_HIGH,
            self::SEVERITY_MEDIUM,
            self::SEVERITY_LOW,
        ])) {
            throw new \InvalidArgumentException("Invalid severity: {$severity}");
        }
        $this->severity = $severity;
        return $this;
    }

    /**
     * Set confidence in root cause identification
     *
     * 0.90+ : Very high confidence (hard evidence)
     * 0.75+ : High confidence (strong correlation)
     * 0.60+ : Medium confidence (likely pattern match)
     * 0.40+ : Low confidence (possible pattern match)
     * <0.40 : Speculative only
     *
     * @param float $confidence 0.0 to 1.0
     *
     * @return self
     */
    public function setConfidence(float $confidence): self
    {
        $this->confidence = max(0.0, min(1.0, $confidence));
        $this->updateStatus();
        return $this;
    }

    /**
     * Set human-readable narrative
     *
     * Example:
     * "PSU Type B aged component failed at 12.8V, causing insufficient power
     * delivery to CPU socket. CPU throttled to 1.2GHz, generating excessive
     * heat. Thermal sensors reported 88°C, crossing warning threshold."
     *
     * @param string $narrative Description of causal chain
     *
     * @return self
     */
    public function setNarrative(string $narrative): self
    {
        $this->narrative = $narrative;
        return $this;
    }

    /**
     * Add remediation step
     *
     * @param string $step Step description (e.g., "Replace PSU")
     * @param int $priority Priority order (1, 2, 3...)
     * @param string $impact Expected outcome
     *
     * @return self
     */
    public function addRemediationStep(string $step, int $priority = 0, string $impact = ''): self
    {
        $this->remediationSteps[] = [
            'step'     => $step,
            'priority' => $priority ?: count($this->remediationSteps) + 1,
            'impact'   => $impact,
        ];
        return $this;
    }

    // ========================================================================
    // Getter Methods
    // ========================================================================

    public function chainId(): string { return $this->chainId; }
    public function type(): string { return $this->type; }
    public function severity(): string { return $this->severity; }
    public function confidence(): float { return $this->confidence; }
    public function status(): string { return $this->status; }

    public function rootEvent(): array { return $this->rootEvent; }
    public function contributingFactors(): array { return $this->contributingFactors; }
    public function intermediateEvents(): array { return $this->intermediateEvents; }
    public function observableSymptoms(): array { return $this->observableSymptoms; }

    public function narrative(): string { return $this->narrative; }
    public function remediationSteps(): array { return $this->remediationSteps; }

    public function eventCount(): int
    {
        return 1 + // root
               count($this->contributingFactors) +
               count($this->intermediateEvents) +
               count($this->observableSymptoms);
    }

    public function timeSpan(): float
    {
        $times = [];

        // Collect all timestamps
        $times[] = strtotime($this->rootEvent['timestamp'] ?? '0');

        foreach ($this->contributingFactors as $factor) {
            $times[] = strtotime($factor['anomaly']['timestamp'] ?? '0');
        }

        foreach ($this->intermediateEvents as $event) {
            $times[] = strtotime($event['anomaly']['timestamp'] ?? '0');
        }

        foreach ($this->observableSymptoms as $symptom) {
            $times[] = strtotime($symptom['anomaly']['timestamp'] ?? '0');
        }

        $times = array_filter($times, fn($t) => $t > 0);
        if (count($times) < 2) return 0;

        return (max($times) - min($times)) / 60; // Minutes
    }

    // ========================================================================
    // Analysis Methods
    // ========================================================================

    /**
     * Calculate chain strength (0-1)
     *
     * Combines:
     * - Confidence in root cause
     * - Number of supporting events
     * - Temporal coherence
     * - Domain causality alignment
     *
     * @return float Chain strength 0.0-1.0
     */
    public function calculateStrength(): float
    {
        $factors = [];

        // Root confidence (40% weight)
        $factors[] = ['weight' => 0.4, 'score' => $this->confidence];

        // Supporting evidence (40% weight)
        $supportCount = count($this->contributingFactors) + count($this->intermediateEvents);
        $evidenceScore = min(1.0, $supportCount / 5); // Scales to 1.0 at 5+ events
        $factors[] = ['weight' => 0.4, 'score' => $evidenceScore];

        // Temporal coherence (10% weight)
        $timespan = $this->timeSpan();
        $temporalScore = $timespan > 0 && $timespan < 3600 ? 1.0 : 0.5; // Good if within 1 hour
        $factors[] = ['weight' => 0.1, 'score' => $temporalScore];

        // Chain completeness (10% weight)
        $hasSymptoms = !empty($this->observableSymptoms);
        $completenessScore = $hasSymptoms ? 1.0 : 0.7;
        $factors[] = ['weight' => 0.1, 'score' => $completenessScore];

        // Weighted average
        $totalWeight = 0;
        $weighted = 0;
        foreach ($factors as $factor) {
            $weighted += $factor['weight'] * $factor['score'];
            $totalWeight += $factor['weight'];
        }

        return $totalWeight > 0 ? $weighted / $totalWeight : 0.0;
    }

    /**
     * Get recommended action based on severity and confidence
     *
     * @return string Recommended action (e.g., 'INVESTIGATE', 'REPAIR_URGENT', 'PLAN_MAINTENANCE')
     */
    public function recommendedAction(): string
    {
        if ($this->severity === self::SEVERITY_CRITICAL && $this->confidence >= 0.80) {
            return 'REPAIR_EMERGENCY';
        }

        if ($this->severity === self::SEVERITY_HIGH && $this->confidence >= 0.75) {
            return 'REPAIR_URGENT';
        }

        if ($this->severity === self::SEVERITY_MEDIUM && $this->confidence >= 0.70) {
            return 'REPAIR_SOON';
        }

        if ($this->severity === self::SEVERITY_LOW || $this->confidence < 0.50) {
            return 'MONITOR_AND_PLAN';
        }

        if ($this->confidence >= 0.60) {
            return 'INVESTIGATE';
        }

        return 'EXPLORE';
    }

    /**
     * Export chain as structured array
     *
     * @return array Complete chain data
     */
    public function toArray(): array
    {
        return [
            'chain_id'              => $this->chainId,
            'type'                  => $this->type,
            'severity'              => $this->severity,
            'confidence'            => round($this->confidence, 3),
            'status'                => $this->status,
            'strength'              => round($this->calculateStrength(), 3),
            'recommended_action'    => $this->recommendedAction(),
            'root_event'            => $this->rootEvent,
            'contributing_factors'  => $this->contributingFactors,
            'intermediate_events'   => $this->intermediateEvents,
            'observable_symptoms'   => $this->observableSymptoms,
            'event_count'           => $this->eventCount(),
            'time_span_minutes'     => round($this->timeSpan(), 1),
            'narrative'             => $this->narrative,
            'remediation_steps'     => $this->remediationSteps,
        ];
    }

    // ========================================================================
    // Private Helpers
    // ========================================================================

    /**
     * Update status based on confidence
     */
    private function updateStatus(): void
    {
        if ($this->confidence >= 0.80) {
            $this->status = 'confirmed';
        } elseif ($this->confidence >= 0.60) {
            $this->status = 'likely';
        } else {
            $this->status = 'speculative';
        }
    }
}
