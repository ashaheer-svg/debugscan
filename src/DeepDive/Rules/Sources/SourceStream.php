<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use App\DeepDive\Rules\LogRecord;

/**
 * SourceStream: Interface for log record iteration across diverse sources
 *
 * PURPOSE:
 * Defines contract for sources of LogRecord events. Allows matchers to
 * consume logs uniformly without knowing whether backing is a real file,
 * rotated log family, or synthetic event stream. Enables abstraction and
 * testability.
 *
 * IMPLEMENTATIONS:
 * 1. FileLogSource: Reads plaintext log files
 *    - Single file or rotation family (messages, messages.1, messages.2...)
 *    - Handles year inference and RFC3164 timestamp parsing
 *    - Lazy evaluation: streams lines as needed
 *
 * 2. SynthSource: In-memory pre-parsed LogRecord array
 *    - Used by SnapshotParsers for /proc snapshots
 *    - Pre-formed records from mdstat, df, vmstat, top
 *    - Allows rules to treat snapshot data like log data
 *
 * 3. Custom implementations: Any source that can emit LogRecord sequence
 *    - Could be HTTP API, message queue, database table
 *    - Must implement name() and records()
 *
 * INTERFACE:
 * - name(): Returns stable logical name ("messages", "mdstat", etc.)
 * - records(): Returns iterable of LogRecord objects
 *
 * RE-ITERABILITY:
 * CRITICAL: records() must return fresh iterator each call.
 * Matchers evaluate multiple rules against same source.
 * If records() returns iterator from shared state, second rule gets
 * already-consumed iterator (no matches). Implementation:
 * - Generator functions: automatically create fresh generator each call
 * - Arrays: foreach loop starts from beginning each time
 * - DO NOT cache iterator state across calls
 *
 * USAGE IN EVALUATION:
 * 1. ParseStep: BundleLocator creates FileLogSource("messages", ...)
 * 2. Registry: Stores source under logical name
 * 3. RegexMatcher: Calls registry.log("messages").records() → iterator
 * 4. Matcher loop: foreach (source.records() as $rec) → fresh iteration
 * 5. AggregateMatcher: Same source, different rule
 * 6. AggregateMatcher calls records() again → gets fresh iterator
 *
 * PERFORMANCE:
 * FileLogSource implements lazy evaluation (generator):
 * - records() returns generator, opens file, yields lines one at a time
 * - If matcher breaks early, file reading stops immediately
 * - Safe for very large log files (not loaded into memory)
 *
 * SynthSource implements array-backed:
 * - Pre-parsed records in memory (small dataset)
 * - records() just yields from pre-existing array
 * - No I/O overhead
 *
 * TIMESTAMP AND METADATA:
 * Each LogRecord includes:
 * - file: Source file path or synthetic identifier
 * - lineNumber: Position in source (for citations)
 * - timestamp: ISO-8601 string (nullable, for age filtering)
 * - text: Full line or normalized text (for pattern matching)
 * - extra: Parser-specific metadata (optional, matcher-dependent)
 *
 * @package App\DeepDive\Rules\Sources
 */
interface SourceStream
{
    /** Stable logical name — e.g. "messages", "kern.log", "scemd.log". */
    public function name(): string;

    /**
     * Fresh iterator of LogRecord objects.
     *
     * @return iterable<LogRecord>
     */
    public function records(): iterable;
}
