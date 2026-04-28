<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * MatcherInterface: Contract for rule evaluation engines
 *
 * PURPOSE:
 * Polymorphic interface for different rule matching strategies
 * Each matcher implements a distinct signature type (regex, SQL, aggregate, absence)
 * Evaluator delegates to appropriate matcher based on rule config
 *
 * MATCHER TYPES:
 * - RegexMatcher: Pattern matching on log files (regex signature)
 * - SqliteMatcher: SQL queries against forensic databases (sqlite signature)
 * - AggregateMatcher: Statistical analysis (count, threshold matching)
 * - AbsenceMatcher: Detect missing expected data (absence signature)
 *
 * EVALUATION CONTRACT:
 * evaluate(rule, registry, limit) returns list of FindingRecord
 * Matchers MUST respect output limit (bounded findings per rule)
 * Prevents noisy rules from overwhelming reports
 * Registry provides access to all data sources (files, SQLite DBs)
 *
 * FINDING GENERATION:
 * Each FindingRecord includes:
 * - ruleId: Which rule matched
 * - severity/actionability: Rule-defined levels
 * - citations: Evidence references with line numbers, timestamps
 * - entities: Context objects for correlation (disks, arrays, IPs, etc.)
 *
 * @package App\DeepDive\Rules\Matchers
 */
interface MatcherInterface
{
    /**
     * Get matcher type identifier
     *
     * TYPES:
     * - 'regex': RegexMatcher
     * - 'sqlite': SqliteMatcher
     * - 'aggregate': AggregateMatcher
     * - 'absence': AbsenceMatcher
     *
     * @return string Signature type
     */
    public function type(): string;

    /**
     * Evaluate rule and return findings
     *
     * RESPONSIBILITY:
     * Parse rule signature (type-specific format)
     * Query registry for relevant data
     * Apply matching logic
     * Generate FindingRecord for each match
     * Enforce output limit (cap findings)
     *
     * OUTPUT BOUNDING:
     * Must return at most $limit findings
     * Prevents noisy rules from consuming all tokens
     * Example: Evaluator passes limit=50
     * Matcher returns first 50 matches (or fewer)
     *
     * CITED EVIDENCE:
     * Each finding must include citations (evidence locations)
     * Citations include: file path, line number, timestamp, excerpt
     * Enable AI to trace findings back to source data
     *
     * ERROR HANDLING:
     * Malformed rules caught by Evaluator (try-catch)
     * Matcher raises exception if signature invalid
     * Other rules unaffected (error isolation)
     *
     * @param Rule $rule Rule with type-specific signature
     * @param SourceRegistry $reg Registry with data sources
     * @param int $limit Maximum findings to return
     *
     * @return list<FindingRecord> Matched findings (<=limit count)
     */
    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array;
}
