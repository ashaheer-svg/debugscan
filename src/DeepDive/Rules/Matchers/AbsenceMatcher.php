<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * AbsenceMatcher: Negative matching (detect missing expected behavior)
 *
 * PURPOSE:
 * Detects abnormal absence of expected events. Complements RegexMatcher
 * (which fires on presence). Examples: web server never started (no "listening"
 * message), authentication subsystem never logged a success (possible lockout),
 * scheduled backup never ran (no completion entry).
 *
 * USE CASES:
 * 1. Subsystem failure: "synoscgi service never logged startup message"
 * 2. Lockout detection: "SSH subsystem never logged successful login"
 * 3. Scheduled task failure: "Backup scheduler never logged job completion"
 * 4. Missing logs: "Expected security audit never logged"
 * 5. Configuration issue: "RAID monitor never reported array status"
 *
 * CORE LOGIC:
 * 1. Find source (e.g. "messages" log)
 * 2. Iterate through ALL records in source
 * 3. If ANY record matches pattern → rule does NOT fire (absence disproven)
 * 4. If NO record matches AND record count >= min_records_to_assert → fire
 * 5. If record count < min_records_to_assert → return [] (insufficient evidence)
 *
 * SIGNATURE CONFIGURATION:
 * - type: "absence" (required)
 * - source: Log source name (e.g. "messages", "auth.log") (required)
 * - pattern: Regex for expected event (required)
 * - min_records_to_assert: Minimum records to confidently call absence (default: 1)
 *
 * MIN_RECORDS_TO_ASSERT (False Positive Prevention):
 * If bundle only contains 50 log lines but expected event typically happens
 * in first 10 lines on normal systems, don't fire on empty bundle. Set
 * min_records_to_assert to 1000 to require substantial evidence of absence.
 * Example: startup messages come in first 100 lines; require 1000+ records
 * before claiming "startup never happened" (implies we have full log).
 *
 * FINDINGS STRUCTURE:
 * Creates single FindingRecord with:
 * - detail: "Expected pattern not found in source"
 * - entities: Rule-defined + {observed_records, span_from, span_to}
 * - citations: [first_record, last_record] to show time span covered
 *
 * CITATION STRATEGY:
 * Cites first and last log records (with timestamps) to show time window.
 * Analysis can then reason: "No event in logs covering Apr 1-15, system
 * was running, so absence is evidence of failure."
 *
 * TIMESTAMP CONTEXT:
 * Uses first/last record timestamps to report covered time period.
 * Timestamps help validate: if span is only 1 hour, maybe insufficient time
 * for expected event to occur. Analysts make final judgment.
 *
 * OUTPUT:
 * Returns [FindingRecord] if absence confirmed, [] otherwise.
 * Maximum 1 finding per rule (absence is binary: present or absent).
 *
 * @package App\DeepDive\Rules\Matchers
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
