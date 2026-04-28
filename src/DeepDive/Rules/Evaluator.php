<?php

declare(strict_types=1);

namespace App\DeepDive\Rules;

use App\DeepDive\Rules\Matchers\AbsenceMatcher;
use App\DeepDive\Rules\Matchers\AggregateMatcher;
use App\DeepDive\Rules\Matchers\MatcherInterface;
use App\DeepDive\Rules\Matchers\RegexMatcher;
use App\DeepDive\Rules\Matchers\SqliteMatcher;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Evaluator: Execute rule catalogue against evidence sources
 *
 * PURPOSE:
 * Applies all rules from catalogue to data in SourceRegistry
 * Produces FindingRecord objects for each rule match
 * Enforces per-rule finding limits to keep reports bounded
 * Captures rule evaluation errors without failing pipeline
 *
 * RULE EXECUTION:
 * For each rule in catalogue:
 * 1. Find matcher for rule's signature type (regex, sqlite, aggregate, absence)
 * 2. Set finding limit (default 50, configurable via rule.signature.max_findings)
 * 3. Call matcher->evaluate() to find all matches
 * 4. Collect findings or capture errors
 * 5. Return all findings across all rules
 *
 * MATCHER TYPES:
 * - RegexMatcher: Pattern matching against log files
 * - SqliteMatcher: SQL queries against database files
 * - AggregateMatcher: Multi-step queries with context
 * - AbsenceMatcher: Evidence NOT present (negative matches)
 * Each matcher implements MatcherInterface with type() and evaluate()
 *
 * FINDING LIMITS:
 * - Default: 50 findings per rule (prevents noise on large bundles)
 * - Clamped: Between 1 and 500 (safety bounds)
 * - Override: Via rule.signature.max_findings field
 * - Purpose: Keep reports bounded even on verbose bundles
 *
 * ERROR HANDLING:
 * Per-rule failures caught and logged, don't block other rules:
 * - Unknown matcher type: Logged as error
 * - Matcher evaluation exception: Caught, logged, continues
 * - All errors collected in $errors array for report appendix
 * - Pipeline never fails due to individual rule errors
 *
 * REPORTING:
 * errors() method provides list of failed rules:
 * - rule_id: Which rule failed
 * - error: Human-readable error message
 * Used in RenderStep appendix for transparency
 *
 * @package App\DeepDive\Rules
 */
final class Evaluator
{
    /** @var array<string,MatcherInterface> Map of matcher type -> matcher instance */
    private array $matchers;

    /** @var list<array{rule_id:string,error:string}> Evaluation errors for report */
    private array $errors = [];

    /**
     * Constructor: Initialize with matchers for rule evaluation
     *
     * DEFAULT MATCHERS:
     * - RegexMatcher: Pattern matching (logs, text files)
     * - SqliteMatcher: SQL queries (databases)
     * - AggregateMatcher: Multi-source correlation
     * - AbsenceMatcher: Negative matches (absence detection)
     *
     * CUSTOM MATCHERS:
     * Pass array to use alternative matcher implementations
     * All must implement MatcherInterface
     *
     * @param ?array $matchers Optional custom matcher instances
     *
     * @throws InvalidArgumentException If matcher doesn't implement MatcherInterface
     */
    public function __construct(?array $matchers = null)
    {
        if ($matchers === null) {
            $matchers = [new RegexMatcher(), new SqliteMatcher(), new AggregateMatcher(), new AbsenceMatcher()];
        }
        $this->matchers = [];
        foreach ($matchers as $m) {
            if (!$m instanceof MatcherInterface) {
                throw new \InvalidArgumentException('Matchers must implement MatcherInterface');
            }
            $this->matchers[$m->type()] = $m;
        }
    }

    /**
     * Evaluate all rules in catalogue against data sources
     *
     * FLOW:
     * 1. Reset error array
     * 2. For each rule in catalogue:
     *    a. Find matcher for signature type
     *    b. Determine finding limit
     *    c. Call matcher->evaluate()
     *    d. Collect results or capture error
     * 3. Return all findings
     *
     * FINDING LIMITS:
     * Default 50 per rule, clamped to 1-500 range
     * Rules can override via signature.max_findings field
     *
     * ERROR RECOVERY:
     * Missing matcher or evaluation error:
     * - Logged to errors[] array
     * - Pipeline continues with other rules
     * - Error details preserved for report
     *
     * @param RuleCatalogue $cat All rules to evaluate
     * @param SourceRegistry $reg Evidence sources (logs, databases)
     * @param int $defaultLimit Default findings per rule (default 50)
     *
     * @return list<FindingRecord> All findings from all rules
     */
    public function evaluate(RuleCatalogue $cat, SourceRegistry $reg, int $defaultLimit = 50): array
    {
        $this->errors = [];
        $all = [];

        foreach ($cat->all() as $rule) {
            $type    = $rule->signatureType();
            $matcher = $this->matchers[$type] ?? null;
            if ($matcher === null) {
                $this->errors[] = ['rule_id' => $rule->id, 'error' => "No matcher for signature type '{$type}'"];
                continue;
            }

            $limit = (int)($rule->signature['max_findings'] ?? $defaultLimit);
            $limit = max(1, min($limit, 500));

            try {
                $found = $matcher->evaluate($rule, $reg, $limit);
            } catch (\Throwable $e) {
                $this->errors[] = ['rule_id' => $rule->id, 'error' => $e->getMessage()];
                continue;
            }
            foreach ($found as $f) $all[] = $f;
        }
        return $all;
    }

    /** @return list<array{rule_id:string,error:string}> */
    public function errors(): array { return $this->errors; }
}
