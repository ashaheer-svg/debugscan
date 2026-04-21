<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * A single rule loaded from YAML. Rules are pure data — matchers interpret
 * them at evaluation time. Keeping this immutable and array-backed lets us
 * serialise rules into the report for auditability ("what triggered this?").
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
