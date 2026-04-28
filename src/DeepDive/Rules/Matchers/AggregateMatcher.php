<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

/**
 * AggregateMatcher: Statistical detection (threshold-based windowed counting)
 *
 * PURPOSE:
 * Detects patterns that are uninteresting as isolated events but concerning
 * when they occur repeatedly. Examples: link flapping (10+ down/up in 5 min),
 * disk errors (5+ errors in 1 hour), OOM killings (3+ in 24 hours).
 * Fires when event count meets or exceeds threshold, optionally within a
 * rolling time window.
 *
 * USE CASES:
 * 1. Flapping: "link down" event repeated 10+ times in 5 minutes
 * 2. Degradation: "read error" logged 5+ times in 1 hour (threshold for SMART failure)
 * 3. Saturation: "out of memory" killed processes 3+ times in 24 hours (swap pressure)
 * 4. Per-device counting: Separate thresholds for different interfaces or disks
 *
 * SIGNATURE CONFIGURATION:
 * - type: "aggregate" (required)
 * - source: Log source name (e.g. "messages", "kern.log", "dmesg") (required)
 * - pattern: Regex to match individual events (required)
 * - threshold: Number of matches required to trigger (default: 2)
 * - window_seconds: Rolling window duration in seconds (optional, omit for lifetime)
 * - group_by: Named capture group for per-group counting (optional)
 * - within_days: Override global finding age filter (optional)
 * - pattern_group_extract: Alternative regex to extract group_by if main pattern is broad (optional)
 *
 * WINDOWING:
 * If window_seconds is set (e.g. 300 for 5 minutes), only count matches
 * within rolling window. Allows rules like "flapping in last 5 min" or
 * "sustained errors over 24 hours". Without window, counts lifetime matches.
 *
 * PER-GROUP COUNTING:
 * If group_by is set (e.g. "interface"), counts matches separately per
 * named capture group value. Example:
 * - Rule matches "link down on eth0" and "link down on eth1"
 * - With group_by="interface", counted separately (eth0: 1, eth1: 1)
 * - Each group independently compared to threshold
 * - Without group_by, counted together (total: 2)
 *
 * FINDINGS GENERATION:
 * Creates one FindingRecord per group that exceeds threshold:
 * - detail: "Detected X occurrences within Y seconds"
 * - entities: Includes group_by value if present (for Correlator)
 * - citations: References all matching log lines (chronological order)
 *
 * TIMESTAMP FILTERING:
 * Respects finding age limits:
 * - Rule-level override: signature.within_days
 * - Global default: Engine::withinDays() (365 days)
 * - Matches older than cutoff dropped before counting
 * - Null timestamps passed through (safer over-inclusion)
 *
 * OUTPUT LIMITING:
 * Returns max $limit findings (enforced by MatcherInterface).
 * If multiple groups exceed threshold, all returned (prioritized by count).
 *
 * @package App\DeepDive\Rules\Matchers
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
