<?php

declare(strict_types=1);

namespace App\DeepDive\Correlation;

use App\DeepDive\Rules\FindingRecord;

/**
 * A coherent group of findings describing one operational issue. Built by
 * the Correlator from raw rule findings.
 *
 * Priority rules (so downstream code doesn't have to re-derive):
 *   critical + any actionability      → P1
 *   high + user_fixable               → P2
 *   high + other                       → P3
 *   warn                               → P3
 *   info                               → P4
 *
 * Actionability is inherited from the root-cause finding — it's what the
 * user needs to *do* about this incident that matters, not the symptoms.
 */
final class Incident
{
    /**
     * @param list<FindingRecord>       $findings       all findings in this incident
     * @param array<string,string>      $sharedEntities e.g. {disk: "sda", array: "md2"}
     * @param list<string>              $causeChain     ordered rule ids from cause → effect
     */
    public function __construct(
        public readonly string        $id,
        public readonly string        $priority,        // P1|P2|P3|P4
        public readonly string        $actionability,
        public readonly string        $title,
        public readonly string        $summary,
        public readonly array         $findings,
        public readonly ?FindingRecord $rootCause,
        public readonly array         $sharedEntities = [],
        public readonly array         $causeChain     = [],
    ) {}
}
