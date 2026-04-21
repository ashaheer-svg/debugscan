<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * One hit from a rule. Stays in-memory during evaluation; the pipeline
 * persists these to deepdive_findings after correlation attaches
 * incident_id.
 *
 * `citations` must be non-empty — a finding without verifiable provenance
 * is not useful to users. Each citation is:
 *   ['file' => string, 'line_number' => int, 'timestamp' => ?string, 'excerpt' => string]
 */
final class FindingRecord
{
    /**
     * @param array<string,mixed>   $entities
     * @param list<array<string,mixed>> $citations
     */
    public function __construct(
        public readonly string  $ruleId,
        public readonly int     $ruleVersion,
        public readonly string  $severity,
        public readonly string  $actionability,
        public readonly string  $title,
        public readonly float   $confidence,
        public readonly array   $entities,
        public readonly array   $citations,
    ) {
        if ($this->citations === []) {
            throw new \InvalidArgumentException("FindingRecord {$this->ruleId}: citations must not be empty");
        }
    }
}
