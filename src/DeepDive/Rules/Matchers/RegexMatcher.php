<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

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
 *   dedupe_by: disk           (optional — aggregate hits with identical entity
 *                              value into ONE finding with occurrenceCount)
 *   within_days: 365          (optional — skip records older than this many days;
 *                              defaults to Engine::withinDays(); 0 = disable)
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

        // Date cutoff: per-rule override, else global default.
        $withinDays = array_key_exists('within_days', $sig)
            ? (int)$sig['within_days']
            : Engine::withinDays();
        $cutoffTs = $withinDays > 0 ? (time() - ($withinDays * 86400)) : 0;

        if ($srcName === '' || $pattern === '') {
            return [];
        }
        $src = $reg->log($srcName);
        if ($src === null) return [];

        $regex        = $this->compile($pattern);
        $excludeRegex = $exclude !== null ? $this->compile($exclude) : null;

        // When dedupeBy is set we accumulate into $groups keyed by dedupe value.
        // When it isn't, each match emits its own finding immediately.
        /** @var array<string,array{
         *   entities: array<string,mixed>,
         *   citations: list<array<string,mixed>>,
         *   count: int,
         * }> $groups */
        $groups   = [];
        $hits     = 0;
        $findings = [];

        foreach ($src->records() as $rec) {
            if ($excludeRegex !== null && preg_match($excludeRegex, $rec->text)) continue;

            $m = [];
            if (!preg_match($regex, $rec->text, $m)) continue;

            // Date filter. We skip if the record has a parseable timestamp and
            // it's older than the cutoff. Records with no parseable timestamp
            // fall through — better to over-include than silently drop.
            if ($cutoffTs > 0 && $rec->timestamp !== null) {
                $ts = strtotime($rec->timestamp);
                if ($ts !== false && $ts < $cutoffTs) continue;
            }

            $entities = $this->bindEntities($rule->entities, $m);

            $citation = [
                'file'        => $rec->file,
                'line_number' => $rec->lineNumber,
                'timestamp'   => $rec->timestamp,
                'excerpt'     => mb_substr($rec->text, 0, 400),
            ];

            if ($dedupeBy !== null) {
                $key = (string)($entities[$dedupeBy] ?? '__no_key__');
                if (!isset($groups[$key])) {
                    $groups[$key] = [
                        'entities'  => $entities,
                        'citations' => [$citation],
                        'count'     => 1,
                    ];
                } else {
                    $g = &$groups[$key];
                    $g['count']++;
                    // Keep at most 5 citations: first + 4 most recent.
                    if (count($g['citations']) < 5) {
                        $g['citations'][] = $citation;
                    } else {
                        // rolling: keep the first (earliest), drop the oldest of
                        // the remaining window, append the newest.
                        array_splice($g['citations'], 1, 1);
                        $g['citations'][] = $citation;
                    }
                    unset($g);
                }
                $hits++;
                continue;
            }

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
                occurrenceCount: 1,
            );
            if (count($findings) >= $limit) break;
        }

        // Flush dedupe groups into FindingRecords.
        if ($dedupeBy !== null) {
            foreach ($groups as $g) {
                if ($g['count'] < $minHits) continue;
                $findings[] = new FindingRecord(
                    ruleId:        $rule->id,
                    ruleVersion:   $rule->version,
                    severity:      $rule->severity,
                    actionability: $rule->actionability,
                    title:         $rule->title,
                    confidence:    0.9,
                    entities:      $g['entities'],
                    citations:     $g['citations'],
                    occurrenceCount: $g['count'],
                );
                if (count($findings) >= $limit) break;
            }
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
