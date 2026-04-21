<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Runs a SELECT against a recovered SQLite file (scemd.db, synocrond.sqlite…).
 * Each returned row emits one finding, with entities populated from
 * $row.<column> placeholders.
 *
 * Signature shape:
 *   type: sqlite
 *   source: scemd.db
 *   query: "SELECT event_time, detail FROM events WHERE severity = :sev"
 *   params:
 *     sev: "critical"
 *   min_rows: 1
 *   citation_column: detail        # optional — column whose value is used as excerpt
 *   timestamp_column: event_time   # optional — column used for citation timestamp
 */
final class SqliteMatcher implements MatcherInterface
{
    public function type(): string { return 'sqlite'; }

    public function evaluate(Rule $rule, SourceRegistry $reg, int $limit): array
    {
        $sig = $rule->signature;
        $srcName = (string)($sig['source'] ?? '');
        $query   = (string)($sig['query']  ?? '');
        $params  = is_array($sig['params'] ?? null) ? $sig['params'] : [];
        $minRows = max(1, (int)($sig['min_rows'] ?? 1));
        $citCol  = (string)($sig['citation_column']  ?? '');
        $tsCol   = (string)($sig['timestamp_column'] ?? '');

        if ($srcName === '' || $query === '') return [];

        $src = $reg->sqlite($srcName);
        if ($src === null) return [];

        // Defensive: force SELECT. SQLite's PRAGMA query_only=ON also enforces
        // this, but bail loudly on obvious abuse so rule authors learn.
        if (!preg_match('/^\s*SELECT\b/i', $query)) {
            throw new \RuntimeException("SqliteMatcher: rule {$rule->id} must use SELECT queries only");
        }

        try {
            $rows = $src->query($query, $params);
        } catch (\Throwable $e) {
            // Missing table / column → rule silently yields nothing.
            return [];
        }
        if (count($rows) < $minRows) return [];

        $findings = [];
        foreach ($rows as $row) {
            $entities = $this->bindEntities($rule->entities, $row);

            $excerpt = $citCol !== '' && isset($row[$citCol])
                ? (string)$row[$citCol]
                : $this->rowSummary($row);
            $ts = $tsCol !== '' && isset($row[$tsCol]) ? (string)$row[$tsCol] : null;

            $citation = [
                'file'        => $src->path(),
                'line_number' => 0,               // sqlite has no line numbers
                'timestamp'   => $ts,
                'excerpt'     => mb_substr($excerpt, 0, 400),
            ];

            $findings[] = new FindingRecord(
                ruleId:        $rule->id,
                ruleVersion:   $rule->version,
                severity:      $rule->severity,
                actionability: $rule->actionability,
                title:         $rule->title,
                confidence:    0.95,
                entities:      $entities,
                citations:     [$citation],
            );
            if (count($findings) >= $limit) break;
        }
        return $findings;
    }

    /** @param array<string,mixed> $row */
    private function rowSummary(array $row): string
    {
        $parts = [];
        foreach ($row as $k => $v) {
            if (is_scalar($v) || $v === null) {
                $parts[] = $k . '=' . (string)$v;
            }
            if (count($parts) >= 6) break;
        }
        return implode(' ', $parts);
    }

    /**
     * @param array<string,mixed> $template
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function bindEntities(array $template, array $row): array
    {
        $out = [];
        foreach ($template as $key => $expr) {
            if (!is_string($expr)) { $out[$key] = $expr; continue; }
            $out[$key] = preg_replace_callback(
                '/\$row\.([a-zA-Z_][a-zA-Z0-9_]*)/',
                static fn(array $h): string => (string)($row[$h[1]] ?? ''),
                $expr
            );
        }
        return $out;
    }
}
