<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * ParserInterface: Contract for diagnostic data extraction
 *
 * PURPOSE:
 * Polymorphic interface for parsing extracted diagnostic files
 * Each parser extracts and structures a specific data domain
 * ParseService orchestrates all parsers in coordinated sequence
 *
 * PARSER CATEGORIES (18 total):
 * Hardware: VersionParser, HardwareParser
 * Storage: DiskParser, RaidParser, VolumeParser, BtrfsScrubParser, DfResultParser, DiskstatsParser
 * System: LogParser, TopResultParser, VmstatParser, DStateParser
 * Network: NetworkParser, NetworkHardwareParser
 * Security: AuthTimelineParser, SmbXferParser
 * Forensics: DatabaseParser (SQLite extraction)
 * Support: TimestampParser (utility for parsing timestamps)
 *
 * PARSING CONTRACT:
 * parse(extractedPath, context) → {data, citations}
 * data: Extracted and structured information
 * citations: Evidence references (file paths, line numbers, timestamps)
 * context: Shared state (DSM version, hardware profile, config)
 *
 * EXECUTION MODEL:
 * ParseService calls parseAll():
 * 1. Respects extraction config (enable/disable sections, row limits)
 * 2. Executes in registration order (VersionParser first, etc.)
 * 3. Passes context between parsers (version → hardware, etc.)
 * 4. Per-parser errors caught; don't block other parsers
 * 5. Aggregates results + citations
 *
 * CITATIONS:
 * Evidence references enable AI to trace findings back to source
 * Each citation includes: file path, line number, timestamp, excerpt
 * Aggregated across all parsers in ParseService._citations
 *
 * CONTEXT PROPAGATION:
 * Shared state passed by reference (&$context)
 * VersionParser sets DSM version → used by HardwareParser
 * Allows parser dependencies without explicit parameters
 * ParseService merges version results into context
 *
 * ERROR HANDLING:
 * Parser exceptions caught by ParseService
 * Failed parser returns: {error: message}
 * Other parsers continue (no cascading failures)
 *
 * @package App\Parsers
 */
interface ParserInterface
{
    /**
     * Parse diagnostic data from extracted directory
     *
     * WORKFLOW:
     * 1. Read relevant files from extractedPath
     * 2. Parse and validate content
     * 3. Structure data (arrays, objects, etc.)
     * 4. Collect evidence citations (file, line, timestamp, excerpt)
     * 5. Return {data, citations}
     *
     * EXTRACTED PATH:
     * Absolute path to extraction directory
     * Files: {section_key}/{filename} (e.g., dsm/var/log/messages)
     * May be missing if file wasn't in debug.dat
     * Parser should handle gracefully (return empty data)
     *
     * CONTEXT:
     * Shared state dict passed between parsers
     * Input: version info, hardware profile, config
     * Output: parser can update context for downstream parsers
     * Reference (&$context): changes visible to next parser
     *
     * RETURN FORMAT:
     * {
     *   'data': {...},  // Extracted data (varies by parser)
     *   'citations': [  // Evidence references
     *     {
     *       'file': 'dsm/var/log/messages',
     *       'line_number': 42,
     *       'timestamp': '2025-04-28 10:30:15',
     *       'excerpt': 'disk failure detected'
     *     },
     *     ...
     *   ]
     * }
     *
     * FALLBACK:
     * Legacy parsers may return just data (no citations)
     * ParseService handles both structures gracefully
     *
     * @param string $extractedPath Root directory of extraction
     * @param array &$context Shared state (by reference, mutable)
     *
     * @return array{data:mixed, citations:list<array<string,mixed>>} Structured output + evidence
     */
    public function parse(string $extractedPath, array &$context): array;
}
