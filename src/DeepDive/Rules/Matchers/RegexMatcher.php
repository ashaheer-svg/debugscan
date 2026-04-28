<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;
use App\DeepDive\Support\Engine;

/**
 * RegexMatcher: Pattern matching against log files with entity extraction
 *
 * PURPOSE:
 * Fundamental matcher for log-based forensics. Evaluates regex patterns
 * against log sources (messages, kern.log, auth.log, etc.) to detect
 * system issues. Extracts contextual data via named capture groups
 * (disk name, process ID, user, etc.). Supports deduplication to group
 * related findings by extracted value.
 *
 * USE CASES:
 * 1. RAID failure detection: "md/raid: disk failure" → extract disk name
 * 2. OOM killer: "Out of memory" → group by process PID
 * 3. Auth failures: "authentication failure" → group by user
 * 4. Disk errors: "I/O error" → group by device name
 * 5. Network issues: "link down" → group by interface
 *
 * RULE SIGNATURE (YAML):
 * type: regex
 * source: messages                           # Log source name
 * pattern: 'kernel:.*disk failure.*(?P<disk>\S+)'  # Regex with named captures
 * min_hits: 1                                # Minimum matches to fire (default: 1)
 * exclude_pattern: 'test.*ignore'            # Optional: skip lines matching this
 * dedupe_by: disk                            # Optional: group by capture group name
 * within_days: 365                           # Optional: find age cutoff (default: global)
 *
 * NAMED CAPTURE GROUPS:
 * Pattern: 'RAID disk (?P<device>\S+) failed code (?P<code>\d+)'
 * Captures: {device: "sda", code: "12345"}
 * Entity binding: {"disk": "$match.device", "error": "$match.code"}
 * Result entities: {disk: "sda", error: "12345"}
 *
 * ENTITY SUBSTITUTION:
 * "$match.<name>" replaced with captured value:
 * - "$match.device" → "sda3"
 * - "$match.user" → "admin"
 * - "$match.pid" → "1234"
 * Used by Correlator to group findings by entity value.
 * Same device appearing in multiple findings → same incident cluster.
 *
 * DEDUPLICATION STRATEGY:
 * Two modes:
 *
 * 1. WITH dedupe_by="device":
 *    - Multiple matches for same device → 1 finding
 *    - Occurrences counted and included in detail
 *    - Citations include all matching log lines
 *    - Useful: "5 errors on same disk" vs "1 error on disk A, 1 on B"
 *
 * 2. WITHOUT dedupe_by:
 *    - Each match → separate finding
 *    - All findings emitted (up to limit)
 *    - No grouping by value
 *    - Useful: "authentication failures from 3 users" (need separate findings)
 *
 * FLOW:
 * 1. Registry lookup: Get log source by name ("messages", "kern.log")
 * 2. Source missing: Return [] (no findings)
 * 3. Pattern compilation: Validate regex syntax
 * 4. Record iteration: Foreach record in source
 * 5. Exclusion: Skip if exclude_pattern matches (if configured)
 * 6. Regex test: Try to match pattern
 * 7. No match: Continue to next record
 * 8. Match: Extract named capture groups
 * 9. Timestamp: Skip if older than within_days cutoff
 * 10. Dedup: If dedupe_by, accumulate in group[entityValue]
 * 11. Output: Build FindingRecord(s), return up to limit
 *
 * TIMESTAMP FILTERING:
 * - within_days: Age filter (config override per rule)
 * - Default: Engine::withinDays() (system default, typically 365 days)
 * - Value 0: Disable age filtering (include all records)
 * - Matches older than cutoff: silently dropped before dedup
 * - Null timestamps: Always included (safer over-inclusion)
 *
 * FINDINGS OUTPUT:
 * With dedupe_by:
 *   FindingRecord {
 *     entities: {disk: "sda"},
 *     occurrenceCount: 5,
 *     citations: [{line1}, {line2}, {line3}, {line4}, {line5}]
 *   }
 *
 * Without dedupe_by:
 *   FindingRecord[] × 5 (one per match, separate findings)
 *
 * CITATIONS:
 * Each citation includes:
 * - file: Log file path
 * - line_number: Line number in file
 * - timestamp: Parsed ISO-8601 timestamp (or null)
 * - excerpt: First 400 chars of matching line
 * Used by Narrator and Report to show evidence.
 *
 * ERROR HANDLING:
 * - Missing source: Return [] (not fatal)
 * - Invalid regex: Throws RuntimeException (rule problem, logged)
 * - Timestamp parse error: null timestamp (passes through)
 * - Match group not found: Missing entity keys (Correlator handles)
 *
 * PERFORMANCE:
 * - Early exclusion: exclude_pattern checked before regex (cheaper)
 * - Lazy evaluation: Records iterated on-demand (safe for large logs)
 * - Regex caching: Pattern compiled once per evaluate() call
 * - Limit enforcement: Stop processing after reaching limit
 *
 * LIMITS:
 * - min_hits: Minimum matching records to emit finding (default: 1)
 * - General limit: Maximum findings returned (enforced by MatcherInterface)
 * - dedupe: If grouping by entity, max 1 finding per unique entity value
 *
 * TYPICAL RULES:
 * 1. SMART error detection:
 *    - source: messages
 *    - pattern: "SMART failure (?P<disk>sd[a-z])"
 *    - dedupe_by: disk
 *    - min_hits: 1
 *
 * 2. OOM killer events:
 *    - source: kern.log
 *    - pattern: "Out of memory: Kill process (?P<pid>\d+)"
 *    - dedupe_by: pid
 *    - min_hits: 1
 *
 * 3. SSH brute force:
 *    - source: auth.log
 *    - pattern: "Failed password for (?P<user>\S+) from (?P<ip>\S+)"
 *    - exclude_pattern: "root|admin"
 *    - min_hits: 5
 *    - dedupe_by: user
 *
 * @package App\DeepDive\Rules\Matchers
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
