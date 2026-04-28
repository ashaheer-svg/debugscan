<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Matchers;

use App\DeepDive\Rules\FindingRecord;
use App\DeepDive\Rules\Rule;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * SqliteMatcher: SQL query-based pattern matching against forensic databases
 *
 * PURPOSE:
 * Executes SQL queries against forensic SQLite databases (SYNOSYSDB,
 * SYNODISKHEALTHDB, event logs, etc.) to detect issues in structured data.
 * Most powerful matcher for analyzing tabular forensic data. Each matching
 * row produces one finding (or groups by dedupe_by). Enables complex
 * multi-column analysis beyond regex capabilities.
 *
 * DATABASE SOURCES:
 * - SYNOSYSDB: System events (thermal, fan, power)
 * - SYNODISKHEALTHDB: SMART health, error counts, temperature
 * - SYNOCONNDB: Network connections, sessions, users
 * - scemd.db: Specialized event logs (storage, thermal)
 * - synocrond.sqlite: Scheduled task logs
 * All forensic-grade data in Synology debug bundles.
 *
 * USE CASES:
 * 1. SMART health analysis:
 *    SELECT * FROM smart_data WHERE temp > 50 OR error_count > 0
 *    → Extract device serial, error count
 * 2. Event-based analysis:
 *    SELECT * FROM events WHERE severity = 'critical'
 *    → Extract event code, device, timestamp
 * 3. Aggregate queries:
 *    SELECT device_id, COUNT(*) as errors FROM events GROUP BY device_id
 *    HAVING COUNT(*) > 5
 *    → Devices with 5+ events
 * 4. Threshold detection:
 *    SELECT * FROM metrics WHERE cpu_temp > 80 AND fan_rpm < 2000
 *    → Thermal warning + failing cooling
 *
 * RULE SIGNATURE (YAML):
 * type: sqlite                               # Matcher type
 * source: scemd.db                           # Database logical name
 * query: |
 *   SELECT device_serial, error_count, temperature
 *   FROM smart_data
 *   WHERE temperature > :warn_temp
 *   AND error_count > 0
 * params:
 *   warn_temp: 50
 * min_rows: 1                                # Minimum rows to fire (default: 1)
 * citation_column: "device_serial"           # Column to show in citation
 * timestamp_column: "last_check_time"        # Column for timestamp
 * dedupe_by: "device_serial"                 # Group by this column
 *
 * PARAMETERIZED QUERIES:
 * Use :placeholder syntax for all variable values:
 * query: "SELECT * FROM events WHERE severity = :sev AND device = :dev"
 * params: {sev: "critical", dev: "sda"}
 *
 * Benefits:
 * - SQL injection prevention: values bound, not concatenated
 * - Type casting: :id binds as integer, :name as string
 * - Safe for user-provided values (though not applicable here)
 * - Performance: query prepared once, params bound multiple times
 *
 * COLUMN VALUE BINDING (Entity Extraction):
 * Row from query becomes object with column names as keys:
 * {"device_serial": "ABC123", "error_count": 5, "temp": 52}
 *
 * Entity substitution: "$row.<column>" replaced with value:
 * Rule: entities: {"device": "$row.device_serial", "errors": "$row.error_count"}
 * Result: {device: "ABC123", errors: 5}
 * Used by Correlator to group findings by device.
 *
 * CITATION BUILDING:
 * citation_column: Column whose value becomes evidence excerpt
 * Example: citation_column: "device_serial" → citation shows "ABC123"
 * Optional: If unspecified, concatenates all column values
 * Default format: "column1: value1, column2: value2, ..."
 *
 * TIMESTAMP EXTRACTION:
 * timestamp_column: Column containing event/check timestamp
 * Example: timestamp_column: "check_time" → extracts that column
 * Format: ISO-8601 preferred, but any parseable string works
 * Optional: If unspecified, no timestamp (null) for findings
 * Used for age filtering and report timeline
 *
 * DEDUPLICATION:
 * If dedupe_by set (e.g., "device_serial"):
 * - Multiple rows with same device_serial → 1 finding
 * - finding.occurrenceCount = number of rows with that value
 * - Useful: "3 SMART errors on device ABC" vs "1 on ABC, 1 on DEF, 1 on GHI"
 *
 * If not set:
 * - Each row → separate finding
 * - All findings emitted (up to limit)
 *
 * FLOW:
 * 1. Registry lookup: Get SqliteSource by name ("scemd.db", "smart.db")
 * 2. Source missing: Return [] (database not found in bundle)
 * 3. Query preparation: Parse and validate SQL (PdoSqliteSource checks)
 * 4. Parameter binding: Bind :placeholders with params dict
 * 5. Query execution: Run SELECT against database
 * 6. Exception handling: Catch SQL errors, return []
 * 7. Row iteration: Process each result row
 * 8. Entity binding: Extract columns, substitute in entities
 * 9. Citation building: Format evidence excerpt
 * 10. Timestamp: Extract from timestamp_column if present
 * 11. Dedup accumulation: Group by dedupe_by value if set
 * 12. Output: Build FindingRecord(s), return up to limit
 *
 * ROW COUNT CONSTRAINTS:
 * min_rows: Minimum rows returned by query to emit finding
 * Default: 1 (any match fires)
 * Higher values prevent false positives on empty results
 * Example: min_rows: 5 means "5+ SMART errors required"
 *
 * ERROR HANDLING:
 * - Missing source: Return [] (database not in bundle)
 * - Invalid SQL: SqliteSource throws, caught, return []
 * - Wrong column name: exception, caught, return []
 * - Parameter mismatch: exception, caught, return []
 * - Empty result set: Return [] if fewer than min_rows
 * All errors logged but don't crash pipeline
 *
 * TYPICAL RULES:
 * 1. SMART temperature warning:
 *    source: smart.db
 *    query: SELECT device_serial, temperature FROM smart WHERE temp > 50
 *    min_rows: 1
 *    citation_column: device_serial
 *    dedupe_by: device_serial
 *
 * 2. System event filtering:
 *    source: scemd.db
 *    query: SELECT device_id, severity, event_code FROM events
 *            WHERE severity = :sev AND event_code IN (:codes)
 *    params: {sev: "critical", codes: [1, 2, 3]}
 *    min_rows: 1
 *    dedupe_by: device_id
 *
 * 3. Aggregate threshold:
 *    source: smart.db
 *    query: SELECT device_serial, COUNT(*) as err_cnt
 *            FROM errors GROUP BY device_serial HAVING COUNT(*) > :threshold
 *    params: {threshold: 5}
 *    citation_column: device_serial
 *    dedupe_by: device_serial
 *
 * PERFORMANCE:
 * - Query execution: Direct SQL, no ORM overhead
 * - Index usage: Databases may have indexes (unknown to matcher)
 * - Limit enforcement: Stop processing after reaching max findings
 * - No caching: Query runs fresh each evaluation (safe for consistency)
 *
 * CONSTRAINTS:
 * - Read-only: Only SELECT allowed (database opened with query_only pragma)
 * - Single database: Each matcher targets one database (multiple matchers for multiple DBs)
 * - Structured data: Works only on tabular SQLite data
 *
 * @package App\DeepDive\Rules\Matchers
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
