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
 * Runs the catalogue against a SourceRegistry and produces FindingRecords.
 *
 * Contract:
 *   - Per-rule evaluation is wrapped in try/catch — one malformed rule
 *     must never kill the whole run. Errors go into $errors for the report
 *     appendix and a (caller-provided) logger.
 *   - Per-rule findings are capped (default 50) to keep the report bounded
 *     on noisy bundles. Authors can override via `signature.max_findings`.
 */
final class Evaluator
{
    /** @var array<string,MatcherInterface> */
    private array $matchers;

    /** @var list<array{rule_id:string,error:string}> */
    private array $errors = [];

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
     * @return list<FindingRecord>
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
