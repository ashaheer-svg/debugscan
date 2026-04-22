<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

/**
 * Fires when a (sub-)regex hits >= threshold times on a source — optionally
 * within a rolling window of N seconds. Used for "flapping" / "burst"
 * style patterns where a single match is uninteresting but many are.
 *
 * Signature shape:
 *   type: aggregate
 *   source: kern.log
 *   pattern: 'link down'
 *   threshold: 10
 *   window_seconds: 300            (optional — omit for lifetime count)
 *   group_by: interface            (optional — count per named-capture group)
 *   pattern_group_extract: '...'   (optional — smaller regex to pull group_by if pattern is broad)
 */
final class AggregateMatcher implements MatcherInterface
{
    public function type(): string { return 'aggregate'; }

    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array
    {
        $sig       = $rule->signature;
        $srcName   = (string)($sig['source']    ?? '');
        $pattern   = (string)($sig['pattern']   ?? '');
        $threshold = max(2, (int)($sig['threshold'] ?? 2));
        $windowSec = isset($sig['window_seconds']) ? (int)$sig['window_seconds'] : 0;
        $groupBy   = isset($sig['group_by']) ? (string)$sig['group_by'] : null;

        // Date cutoff: per-rule override, else global default.
        $withinDays = array_key_exists('within_days', $sig)
            ? (int)$sig['within_days']
            : Engine::withinDays();
        $cutoffTs = $withinDays > 0 ? (time() - ($withinDays * 86400)) : 0;

        if ($srcName === '' || $pattern === '') return [];
        $src = $reg->log($srcName);
        if ($src === null) return [];

        $regex = $this->compile($pattern);

        /**
         * hits[groupKey] = [
         *   'count'     => int,
         *   'first_ts'  => ?string,
         *   'last_ts'   => ?string,
         *   'citations' => list<array>,
         *   'window'    => list<int>  // unix timestamps for rolling window
         * ]
         */
        $hits = [];

        foreach ($src->records() as $rec) {
            $m = [];
            if (!preg_match($regex, $rec->text, $m)) continue;

            // Date filter: drop records older than cutoff. Records with no
            // parseable timestamp fall through (better to over-include than
            // silently drop).
            if ($cutoffTs > 0 && $rec->timestamp !== null) {
                $ts = strtotime($rec->timestamp);
                if ($ts !== false && $ts < $cutoffTs) continue;
            }

            $key = $groupBy !== null
                ? ((string)($m[$groupBy] ?? '__all__'))
                : '__all__';

            if (!isset($hits[$key])) {
                $hits[$key] = [
                    'count'     => 0,
                    'first_ts'  => $rec->timestamp,
                    'last_ts'   => $rec->timestamp,
                    'citations' => [],
                    'window'    => [],
                ];
            }

            $hits[$key]['count']++;
            $hits[$key]['last_ts'] = $rec->timestamp ?? $hits[$key]['last_ts'];

            if ($windowSec > 0 && $rec->timestamp !== null) {
                $t = strtotime($rec->timestamp);
                if ($t !== false) {
                    $hits[$key]['window'][] = $t;
                    $cutoff = $t - $windowSec;
                    while (!empty($hits[$key]['window']) && $hits[$key]['window'][0] < $cutoff) {
                        array_shift($hits[$key]['window']);
                    }
                }
            }

            // Keep at most 5 example citations per group to bound report size.
            if (count($hits[$key]['citations']) < 5) {
                $hits[$key]['citations'][] = [
                    'file'        => $rec->file,
                    'line_number' => $rec->lineNumber,
                    'timestamp'   => $rec->timestamp,
                    'excerpt'     => mb_substr($rec->text, 0, 400),
                ];
            }
        }

        $findings = [];
        foreach ($hits as $key => $agg) {
            $trigger = $windowSec > 0
                ? count($agg['window']) >= $threshold
                : $agg['count']          >= $threshold;
            if (!$trigger) continue;

            $entities = array_merge(
                $rule->entities,
                [
                    'group'     => $key,
                    'count'     => (string)$agg['count'],
                    'first_ts'  => (string)($agg['first_ts'] ?? ''),
                    'last_ts'   => (string)($agg['last_ts'] ?? ''),
                    'window_s'  => (string)$windowSec,
                ]
            );

            $findings[] = new FindingRecord(
                ruleId:        $rule->id,
                ruleVersion:   $rule->version,
                severity:      $rule->severity,
                actionability: $rule->actionability,
                title:         $rule->title,
                confidence:    0.85,
                entities:      $entities,
                citations:     $agg['citations'] ?: [[
                    'file' => '(aggregate)', 'line_number' => 0,
                    'timestamp' => null, 'excerpt' => "count={$agg['count']}",
                ]],
            );
            if (count($findings) >= $limit) break;
        }
        return $findings;
    }

    private function compile(string $pattern): string
    {
        if ($pattern === '') return '//';
        $first = $pattern[0];
        if (in_array($first, ['/', '~', '#', '%'], true) && strrpos($pattern, $first) > 0) return $pattern;
        return '/' . str_replace('/', '\/', $pattern) . '/i';
    }
}
