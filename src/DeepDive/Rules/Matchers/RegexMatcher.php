<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Matches a single regex over a logical log source. Captures (named groups)
 * are bound into entities via the "$match.<name>" substitution syntax.
 *
 * Signature shape:
 *   type: regex
 *   source: messages
 *   pattern: 'md/raid:md\d+ Disk failure on (?P<disk>\S+)'
 *   min_hits: 1               (optional, default 1 — floor for triggering)
 *   exclude_pattern: '...'    (optional — skip records matching this)
 *   dedupe_by: disk           (optional — collapse hits with identical entity value)
 */
final class RegexMatcher implements MatcherInterface
{
    public function type(): string { return 'regex'; }

    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array
    {
        $sig      = $rule->signature;
        $srcName  = (string)($sig['source']  ?? '');
        $pattern  = (string)($sig['pattern'] ?? '');
        $minHits  = max(1, (int)($sig['min_hits'] ?? 1));
        $exclude  = isset($sig['exclude_pattern']) ? (string)$sig['exclude_pattern'] : null;
        $dedupeBy = isset($sig['dedupe_by']) ? (string)$sig['dedupe_by'] : null;

        if ($srcName === '' || $pattern === '') {
            return []; // silent no-op; caller logs at catalogue-load if concerned
        }
        $src = $reg->log($srcName);
        if ($src === null) return [];

        $regex        = $this->compile($pattern);
        $excludeRegex = $exclude !== null ? $this->compile($exclude) : null;

        $hits        = 0;
        $findings    = [];
        $seenDedupe  = [];

        foreach ($src->records() as $rec) {
            if ($excludeRegex !== null && preg_match($excludeRegex, $rec->text)) continue;

            $m = [];
            if (!preg_match($regex, $rec->text, $m)) continue;

            $entities = $this->bindEntities($rule->entities, $m);

            if ($dedupeBy !== null) {
                $key = (string)($entities[$dedupeBy] ?? '__no_key__');
                if (isset($seenDedupe[$key])) continue;
                $seenDedupe[$key] = true;
            }

            $citation = [
                'file'        => $rec->file,
                'line_number' => $rec->lineNumber,
                'timestamp'   => $rec->timestamp,
                'excerpt'     => mb_substr($rec->text, 0, 400),
            ];

            $hits++;
            if ($hits < $minHits) continue;

            $findings[] = new FindingRecord(
                ruleId:        $rule->id,
                ruleVersion:   $rule->version,
                severity:      $rule->severity,
                actionability: $rule->actionability,
                title:         $rule->title,
                confidence:    0.9,
                entities:      $entities,
                citations:     [$citation],
            );
            if (count($findings) >= $limit) break;
        }
        return $findings;
    }

    /** Accepts either a raw pattern (we wrap in delimiters) or a pre-delimited one. */
    private function compile(string $pattern): string
    {
        if ($pattern === '') return '//';
        // If the author already delimited with / ~ # %, trust them.
        $first = $pattern[0];
        if (in_array($first, ['/', '~', '#', '%'], true) && strrpos($pattern, $first) > 0) {
            return $pattern;
        }
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }

    /**
     * Render the rule's `entities` template, substituting $match.<name>
     * for named captures, $match[<n>] for numeric.
     *
     * @param array<string,mixed> $template
     * @param array<string,mixed> $m preg_match result
     * @return array<string,mixed>
     */
    private function bindEntities(array $template, array $m): array
    {
        $out = [];
        foreach ($template as $key => $expr) {
            if (!is_string($expr)) { $out[$key] = $expr; continue; }
            $out[$key] = preg_replace_callback(
                '/\$match\.([a-zA-Z_][a-zA-Z0-9_]*)|\$match\[(\d+)\]/',
                function (array $hit) use ($m): string {
                    if (!empty($hit[1])) return (string)($m[$hit[1]] ?? '');
                    return (string)($m[(int)$hit[2]] ?? '');
                },
                $expr
            );
        }
        return $out;
    }
}
