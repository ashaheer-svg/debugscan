<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * "Expected line never appeared" detector. Fires if a source exists but
 * contains zero matches for the signature pattern — useful for catching
 * subsystems that never successfully started.
 *
 * Signature shape:
 *   type: absence
 *   source: messages
 *   pattern: 'synoscgi: Successful login'
 *   min_records_to_assert: 1000   (optional — avoid false positives on tiny bundles)
 *
 * The citation points at the first and last records of the source to give
 * the analyst an anchor.
 */
final class AbsenceMatcher implements MatcherInterface
{
    public function type(): string { return 'absence'; }

    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array
    {
        $sig       = $rule->signature;
        $srcName   = (string)($sig['source']  ?? '');
        $pattern   = (string)($sig['pattern'] ?? '');
        $minCount  = max(1, (int)($sig['min_records_to_assert'] ?? 1));
        if ($srcName === '' || $pattern === '') return [];
        $src = $reg->log($srcName);
        if ($src === null) return [];

        $regex = $this->compile($pattern);

        $count = 0;
        $first = null;
        $last  = null;
        foreach ($src->records() as $rec) {
            $count++;
            $first ??= $rec;
            $last    = $rec;
            if (preg_match($regex, $rec->text)) return []; // pattern present → rule doesn't fire
        }
        if ($count < $minCount) return []; // not enough evidence to call absence

        if ($first === null || $last === null) return [];

        $entities = array_merge($rule->entities, [
            'observed_records' => (string)$count,
            'span_from'        => (string)($first->timestamp ?? ''),
            'span_to'          => (string)($last->timestamp  ?? ''),
        ]);

        $citations = [
            [
                'file'        => $first->file,
                'line_number' => $first->lineNumber,
                'timestamp'   => $first->timestamp,
                'excerpt'     => mb_substr("(first of {$count}) " . $first->text, 0, 400),
            ],
            [
                'file'        => $last->file,
                'line_number' => $last->lineNumber,
                'timestamp'   => $last->timestamp,
                'excerpt'     => mb_substr("(last of {$count}) "  . $last->text, 0, 400),
            ],
        ];

        return [new FindingRecord(
            ruleId:        $rule->id,
            ruleVersion:   $rule->version,
            severity:      $rule->severity,
            actionability: $rule->actionability,
            title:         $rule->title,
            confidence:    0.7,      // absence is inherently less certain than presence
            entities:      $entities,
            citations:     $citations,
        )];
    }

    private function compile(string $pattern): string
    {
        if ($pattern === '') return '//';
        $first = $pattern[0];
        if (in_array($first, ['/', '~', '#', '%'], true) && strrpos($pattern, $first) > 0) return $pattern;
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }
}
