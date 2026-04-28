<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

use App\DeepDive\Rules\LogRecord;
use App\DeepDive\Rules\Sources\SourceStream;

/**
 * SynthSource: In-memory SourceStream for snapshot data
 *
 * PURPOSE:
 * Adapts pre-parsed LogRecord arrays (from SnapshotParsers) into SourceStream
 * interface so matchers can consume them uniformly with FileLogSource and
 * SqliteSource. Enables rules to reference synthetic snapshot sources
 * ("mdstat", "df", "top_snapshot") exactly like real logs ("messages", "auth.log").
 *
 * USAGE PATTERN:
 * 1. SnapshotParsers parses /proc/mdstat file → array of LogRecord
 * 2. Creates SynthSource("mdstat", records)
 * 3. Registers in SourceRegistry: reg.registerLog(synthSource)
 * 4. Rules access: matcher evaluates against source "mdstat"
 * 5. SourceRegistry returns SynthSource, matchers iterate records()
 * 6. Matchers never know source came from synthetic stream vs. real file
 *
 * INTERFACE:
 * Implements SourceStream with two methods:
 * - name(): Returns logical source name ("mdstat", "df", etc.)
 * - records(): Yields LogRecord objects (iterable, generator-compatible)
 *
 * ADVANTAGES:
 * - No disk I/O: Records already parsed and in memory
 * - No lazy loading: All records available immediately
 * - Simple: Just stores list and yields on demand
 * - Matcher-agnostic: Works with regex, aggregate, or any matcher
 *
 * LIFECYCLE:
 * Created fresh during ParseStep for each job. Records list passed to constructor
 * is immutable - no modifications after registration. Single source per snapshot
 * type per bundle.
 *
 * @package App\DeepDive\Parsers
 */
final class SynthSource implements SourceStream
{
    /**
     * @param list<LogRecord> $records
     */
    public function __construct(
        private readonly string $name,
        private readonly array  $records,
    ) {}

    public function name(): string { return $this->name; }

    public function records(): iterable
    {
        foreach ($this->records as $r) yield $r;
    }
}
