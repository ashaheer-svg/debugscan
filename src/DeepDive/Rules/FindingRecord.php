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
     * @param int  $occurrenceCount  total hits collapsed into this finding
     *                               (>=1; only > 1 when the rule uses dedupe_by
     *                               and multiple source records matched the same key)
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
        public readonly int     $occurrenceCount = 1,
    ) {
        if ($this->citations === []) {
            throw new \InvalidArgumentException("FindingRecord {$this->ruleId}: citations must not be empty");
        }
    }
}
