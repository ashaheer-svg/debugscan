<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * One line (or one decoded event) from a parsed log artifact, with provenance.
 *
 * Parsers in Sprint 2b produce these. The rule evaluator consumes them.
 * Kept deliberately small — anything richer lives in the parser-specific
 * Bundle objects, which rules can still reach via SourceRegistry.
 */
final class LogRecord
{
    public function __construct(
        public readonly string  $file,
        public readonly int     $lineNumber,
        public readonly ?string $timestamp,  // ISO-8601 string or null if unparseable
        public readonly string  $text,
        public readonly array   $extra = [], // parser-specific extras (hostname, pid, facility, etc.)
    ) {}
}
