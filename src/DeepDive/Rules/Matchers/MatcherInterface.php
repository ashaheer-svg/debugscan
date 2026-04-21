<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

interface MatcherInterface
{
    /** Signature type this matcher handles (regex|sqlite|aggregate|absence). */
    public function type(): string;

    /**
     * Evaluate the rule against the registry. Matchers MUST cap output at
     * $limit findings per rule to keep the report bounded on noisy bundles.
     *
     * @return list<FindingRecord>
     */
    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array;
}
