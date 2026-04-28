<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

/**
 * LogRecord: Single log event with full provenance and parsing context
 *
 * PURPOSE:
 * Represents one line (or one structured event) extracted from a parsed log
 * artifact. LogRecords are produced by parsers and consumed by matchers during
 * rule evaluation. Keeps the record lightweight - rich parser-specific data
 * remains in parser bundles accessible via SourceRegistry if needed.
 *
 * STRUCTURE:
 * - file: Relative path in bundle (e.g. "dsm/var/log.syslog", "dsm/var/log.messages")
 * - lineNumber: Line number in source file (1-indexed for user-facing messages)
 * - timestamp: ISO-8601 string parsed from log line, nullable if unparseable
 * - text: Full log line as plain string for regex matchers
 * - extra: Parser-specific metadata (hostname, syslog facility, PID, command name, etc.)
 *
 * TIMESTAMP HANDLING:
 * Timestamp is optional because log formats vary wildly:
 * - syslog: "Apr 28 10:23:45 nas kernel: ..."
 * - custom formats: "2026-04-28T10:23:45Z"
 * - unstructured: no timestamp field
 * TimestampParser attempts to extract and normalize to ISO-8601.
 * Null timestamp is valid - matchers handle gracefully (no time filtering).
 *
 * EXTRA FIELD (Parser-Specific):
 * Contains whatever metadata the parser extracted:
 * - syslog: {facility: "kern", severity: "err", pid: 1234, command: "md"}
 * - auth logs: {user: "admin", ip: "192.168.1.5", protocol: "ssh", result: "success"}
 * - kernel logs: {subsystem: "raid", event: "failure"}
 * Matchers can access via LogRecord->extra['key'] without knowing parser type.
 *
 * PROVENANCE:
 * File and lineNumber together create full audit trail. Reports can cite:
 * "Line 1234 of dsm/var/log.messages matched rule storage.raid_degraded"
 * Enables Correlator to find source evidence in original bundle.
 *
 * USAGE:
 * Created by parsers (LogParser, etc.) from raw log lines.
 * Passed to SourceStream, which streams them to matchers.
 * RegexMatcher extracts capture groups, AggregateMatcher accumulates counts.
 *
 * @package App\DeepDive\Rules
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
