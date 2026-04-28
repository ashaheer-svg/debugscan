<?php

declare(strict_types=1);

namespace App\DeepDive\Correlation;

use App\DeepDive\Rules\FindingRecord;

/**
 * Incident: Coherent group of related findings describing one operational issue
 *
 * PURPOSE:
 * Represents a single actionable problem combining multiple findings
 * Built by Correlator from raw rule findings via correlation analysis
 * Includes causality chain, shared context, and remediation guidance
 *
 * INCIDENT vs FINDING:
 * Finding: Individual rule match (e.g., "disk sda bad sectors")
 * Incident: Related findings grouped as single issue (e.g., "disk sda failure")
 * Incidents enable better reporting and prioritization
 *
 * PRIORITY ASSIGNMENT:
 * Automatic calculation based on findings and actionability:
 * - P1 (Critical): Critical severity + any actionability (must fix immediately)
 * - P2 (High): High severity + user_fixable (user can fix)
 * - P3 (High): High severity + other (requires vendor/upgrade) OR warning severity
 * - P4 (Info): Informational severity (FYI only)
 *
 * ACTIONABILITY:
 * Inherited from root-cause finding - represents what user must DO:
 * - user_fixable: User can resolve (cables, settings, etc.)
 * - upgrade_recommended: Needs hardware upgrade
 * - vendor_issue: Vendor/firmware problem (no user action)
 * - informational: No action needed
 *
 * CORRELATION CONTEXT:
 * - Shared entities: Common objects (disk "sda", array "md2")
 * - Cause chain: Ordered rule IDs showing causality (root → effect)
 * - Root cause: Original finding causing cascade
 *
 * INCIDENT PROPERTIES:
 * - id: UUID for incident tracking
 * - priority: P1/P2/P3/P4 for report sorting
 * - actionability: What user needs to do
 * - title: Human-readable incident name
 * - summary: Concise problem description
 * - findings: All related FindingRecord objects
 * - rootCause: Original finding (nullable)
 * - sharedEntities: Entities mentioned across findings
 * - causeChain: Rule IDs in causal order
 *
 * @package App\DeepDive\Correlation
 */
final class Incident
{
    /**
     * Constructor: Initialize incident with findings and metadata
     *
     * @param string $id Unique incident identifier (UUID)
     * @param string $priority Priority level (P1|P2|P3|P4)
     * @param string $actionability What user needs to do (user_fixable|upgrade|vendor|info)
     * @param string $title Human-readable incident title
     * @param string $summary Concise problem description
     * @param list<FindingRecord> $findings All related findings
     * @param ?FindingRecord $rootCause Original finding (nullable)
     * @param array<string,string> $sharedEntities Common objects {entity_type: value}
     * @param list<string> $causeChain Rule IDs in causal order (root → effect)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $priority,        // P1|P2|P3|P4
        public readonly string $actionability,
        public readonly string $title,
        public readonly string $summary,
        public readonly array $findings,
        public readonly ?FindingRecord $rootCause,
        public readonly array $sharedEntities = [],
        public readonly array $causeChain = [],
    ) {}
}
