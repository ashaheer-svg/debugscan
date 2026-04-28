<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * FindingRecord: Individual rule match from evaluation
 *
 * PURPOSE:
 * Represents one successful rule match against evidence
 * Includes evidence citations, confidence, severity, and context
 * Stays in-memory during evaluation, persisted after correlation
 *
 * LIFECYCLE:
 * 1. Created during rule evaluation (Evaluator/Matcher)
 * 2. Stored in-memory with other findings
 * 3. Grouped into Incidents by Correlator
 * 4. Incident ID attached post-correlation
 * 5. Persisted to deepdive_findings table
 *
 * FIELDS:
 * - ruleId: Rule that matched
 * - ruleVersion: Rule version at evaluation time
 * - severity: critical/high/warning/info
 * - actionability: user_fixable/upgrade/vendor/informational
 * - title: Human-readable finding name
 * - confidence: Match confidence 0.0-1.0
 * - entities: Context objects (disk, array, interface, etc.)
 * - citations: Evidence references with line numbers
 * - occurrenceCount: Deduplicated match count
 *
 * CITATIONS (Required):
 * Must be non-empty (enforced by constructor)
 * Each citation includes:
 * - file: Source file path (e.g., dsm/var/log/messages)
 * - line_number: Line where evidence found
 * - timestamp: When evidence was recorded (nullable)
 * - excerpt: Actual evidence text/line
 *
 * ENTITIES:
 * Context for correlation:
 * - disk/device: Drive references
 * - array: RAID array ID
 * - interface/iface: Network interface
 * - mount: Filesystem mount point
 * - process: Process name/ID
 * Used to link related findings into incidents
 *
 * OCCURRENCE COUNT:
 * Deduplicated match count (>=1)
 * > 1 when rule uses dedupe_by and multiple records match same key
 * Useful for understanding finding frequency
 *
 * @package App\DeepDive\Rules
 */
final class FindingRecord
{
    /**
     * Constructor: Initialize finding with evidence and context
     *
     * @param string $ruleId Matching rule ID
     * @param int $ruleVersion Rule version at match time
     * @param string $severity Severity level (critical|high|warning|info)
     * @param string $actionability Action type (user_fixable|upgrade|vendor|informational)
     * @param string $title Human-readable finding title
     * @param float $confidence Match confidence (0.0-1.0)
     * @param array<string,mixed> $entities Context objects for correlation
     * @param list<array<string,mixed>> $citations Evidence references (required, non-empty)
     * @param int $occurrenceCount Deduplicated match count (default 1)
     *
     * @throws InvalidArgumentException If citations empty
     */
    public function __construct(
        public readonly string $ruleId,
        public readonly int $ruleVersion,
        public readonly string $severity,
        public readonly string $actionability,
        public readonly string $title,
        public readonly float $confidence,
        public readonly array $entities,
        public readonly array $citations,
        public readonly int $occurrenceCount = 1,
    ) {
        if ($this->citations === []) {
            throw new \InvalidArgumentException("FindingRecord {$this->ruleId}: citations must not be empty");
        }
    }
}
