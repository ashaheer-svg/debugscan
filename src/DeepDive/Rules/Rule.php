<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * Rule: Immutable forensic detection rule definition
 *
 * PURPOSE:
 * Represents a single forensic rule loaded from YAML rule files. Rules are pure
 * data structures - the Evaluator and various matchers interpret them at runtime.
 * Each rule encodes a forensic hypothesis: "IF evidence matches this pattern,
 * THEN a system fault exists with this severity and actionability."
 *
 * RULE STRUCTURE:
 * - id: Unique identifier (e.g. "storage.raid_degraded"), used in correlate graph
 * - version: Integer for rule versioning (allows backwards-compatible updates)
 * - title: Human-readable rule name ("RAID Array Degraded")
 * - category: Classification (storage, network, memory, security)
 * - severity: Impact level (info, warn, high, critical)
 * - actionability: User ability to fix (user_fixable, upgrade_recommended, vendor_issue, informational)
 * - signature: Matcher-specific detection logic (regex, sqlite, aggregate, absence)
 * - entities: Entity extraction templates (e.g. {disk: "$match.disk"} for Correlator)
 * - description: Long-form explanation of the issue
 * - remediation: User-facing fix instructions
 * - tags: Array of keywords for categorization
 *
 * SIGNATURE TYPES:
 * - regex: Pattern matching against log files with named capture groups
 * - sqlite: SQL query against forensic SQLite databases
 * - aggregate: Statistical matching (threshold-based windowed counts)
 * - absence: Negative matching (detect missing expected data)
 * Each type has specific config in signature{} block interpreted by corresponding matcher.
 *
 * ENTITIES (Correlator Input):
 * Templates like {disk: "$match.disk"} extract facts from match results.
 * Correlator uses entity values to group findings into incident clusters.
 * Example: two rules matching with same disk value are clustered together.
 *
 * IMMUTABILITY:
 * Rules are read-only after construction. This enables:
 * - Safe serialization into reports (evidence of what rules matched)
 * - Content hashing for rule catalogue versioning (SHA256 of all rules)
 * - Thread-safe caching in RuleCatalogue
 *
 * VALIDATION:
 * assertValid() called by RuleLoader after construction. Checks:
 * - ID format (lowercase, digits, dots, underscores only)
 * - Version >= 1
 * - Severity in allowed set
 * - Actionability in allowed set
 * - Signature type in SIG_TYPES
 *
 * @package App\DeepDive\Rules
 */
final class Rule
{
    public const SEVERITIES      = ['info', 'warn', 'high', 'critical'];
    public const ACTIONABILITIES = ['user_fixable', 'upgrade_recommended', 'vendor_issue', 'informational'];
    public const SIG_TYPES       = ['regex', 'sqlite', 'aggregate', 'absence'];

    public function __construct(
        public readonly string $id,
        public readonly int    $version,
        public readonly string $title,
        public readonly string $severity,
        public readonly string $actionability,
        public readonly string $category,
        public readonly array  $signature,     // raw signature block, matcher-specific
        public readonly array  $entities,      // entity templates (e.g. {disk: "$match.disk"})
        public readonly ?string $description = null,
        public readonly ?string $remediation  = null,
        /** @var list<string> */
        public readonly array  $tags = [],
    ) {}

    public function signatureType(): string
    {
        return (string)($this->signature['type'] ?? '');
    }

    /** Sanity check — called by RuleLoader after construction. */
    public function assertValid(): void
    {
        if ($this->id === '' || !preg_match('/^[a-z0-9_.-]+$/', $this->id)) {
            throw new \InvalidArgumentException("Rule id is empty or malformed: {$this->id}");
        }
        if ($this->version < 1) {
            throw new \InvalidArgumentException("Rule {$this->id}: version must be >= 1");
        }
        if (!in_array($this->severity, self::SEVERITIES, true)) {
            throw new \InvalidArgumentException("Rule {$this->id}: invalid severity '{$this->severity}'");
        }
        if (!in_array($this->actionability, self::ACTIONABILITIES, true)) {
            throw new \InvalidArgumentException("Rule {$this->id}: invalid actionability '{$this->actionability}'");
        }
        $type = $this->signatureType();
        if (!in_array($type, self::SIG_TYPES, true)) {
            throw new \InvalidArgumentException("Rule {$this->id}: unknown signature.type '{$type}'");
        }
    }
}
