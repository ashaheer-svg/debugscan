<?php

declare(strict_types=1);

namespace App\Services;

/**
 * PackagingService: Format diagnostic data for AI analysis
 *
 * PURPOSE:
 * Transform raw/structured data into optimized formats for LLM consumption
 * Mix of markdown tables, JSON, and tiered text representations
 * Reduce token consumption while preserving key information
 *
 * FORMATTING PATTERNS:
 * - Format A (JSON): For dense data (disk health, load profiles) — machine-friendly
 * - Format B (Markdown Table): For event streams (system events) — readable
 * - Format C (Tiered): For complex logs (connections, disk events) — summary + detail
 * - Format D (Markdown): For state data (btrfs scrub) — free-form
 * - Format E (JSON): For multi-section data (load profile) — structured
 *
 * TOKEN OPTIMIZATION:
 * Markdown tables: 30-50% more efficient than JSON for columnar data
 * Summaries before details: LLM gets context before drowning in logs
 * Pipe character escaping: Prevents markdown table corruption
 * Limited row counts in formatters: Controlled by caller (ExtractionConfigService)
 *
 * INTEGRATION:
 * Called by database parsers (DatabaseParser, DiskstatsParser, etc.)
 * Receives raw parser output (arrays, timestamps, counts)
 * Returns pre-formatted string for inclusion in AI prompt
 * Enables consistent formatting across all data sources
 *
 * @package App\Services
 */
class PackagingService
{
    /**
     * Format Unix timestamp to readable datetime string
     *
     * FORMAT:
     * Y-m-d H:i:s (e.g., "2025-04-28 14:30:15")
     * ISO-8601 compatible, human-readable for AI analysis
     *
     * USED BY:
     * All format*() methods when adding timestamps to output
     *
     * @param int $unix Unix timestamp (seconds since epoch)
     *
     * @return string Formatted datetime string
     */
    private function ts(int $unix): string
    {
        return date('Y-m-d H:i:s', $unix);
    }

    /**
     * Format system events as markdown table with summary
     *
     * INPUT:
     * {
     *   'summary': {'total': 500, 'errs': 50, 'warns': 100},
     *   'rows': [{time, level, username, msg}, ...]
     * }
     *
     * OUTPUT:
     * Markdown section with summary line and table
     * Pipe characters in messages escaped (\|)
     * Messages truncated to 500 chars
     *
     * USAGE:
     * DatabaseParser output for SYNOSYSDB events
     * AI analysis of recent system state changes
     *
     * @param array<string,mixed> $data Summary + rows array
     *
     * @return string Markdown-formatted table
     */
    public function formatSystemEvents(array $data): string
    {
        $summary = $data['summary'] ?? [];
        $rows = $data['rows'] ?? [];

        $lines = [
            "## [SYNOSYSDB] System Events — Last 12 Months",
            "Summary: Total: " . ($summary['total'] ?? 0) . " | Errors: " . ($summary['errs'] ?? 0) . " | Warnings: " . ($summary['warns'] ?? 0),
            "",
            "| Timestamp | Level | User | Event |",
            "|---|---|---|---|",
        ];

        foreach ($rows as $r) {
            $msg = str_replace('|', '\|', (string)$r['msg']);
            $lines[] = sprintf("| %s | %s | %s | %s |", 
                $this->ts((int)$r['time']), 
                $r['level'], 
                $r['username'] ?: 'SYSTEM', 
                mb_strimwidth($msg, 0, 500, '...')
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Format disk health data as structured JSON
     *
     * Contains SMART error counters and failure prediction scores
     * Source: SYNODISKHEALTHDB (lifetime drive health tracking)
     * Most direct evidence of physical drive failure risk
     *
     * @param array<string,mixed> $data {disk_error: [...], prediction: [...]}
     *
     * @return string Prettified JSON
     */
    public function formatDiskHealth(array $data): string
    {
        return json_encode([
            'source' => '[SYNODISKHEALTHDB]',
            'note' => 'Lifetime counters + 12-month failure predictions',
            'disk_error' => $data['disk_error'] ?? [],
            'prediction' => $data['prediction'] ?? []
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Format connection logs with tiered analysis (summary + detail)
     *
     * Includes: summary by protocol/level, brute-force clusters, critical events
     * Brute-force detection: IP + username + failure count + time range
     * Critical events: Detailed table of security-relevant log entries
     *
     * @param array<string,mixed> $data {summary_groups, brute_force, critical_events}
     *
     * @return string Markdown with tiered sections
     */
    public function formatConnections(array $data): string
    {
        $summary = $data['summary_groups'] ?? [];
        $bruteForce = $data['brute_force'] ?? [];
        $critical = $data['critical_events'] ?? [];

        $lines = [
            "## [SYNOCONNDB] Connection & Access Log — Tiered Analysis",
            "SUMMARY STATS:",
        ];

        foreach ($summary as $s) {
            $lines[] = sprintf("  - %s (%s): %d events", $s['protocol'], $s['level'], $s['cnt']);
        }

        if (!empty($bruteForce)) {
            $lines[] = "\nBRUTE-FORCE / FAILED LOGIN CLUSTERS:";
            foreach ($bruteForce as $bf) {
                $lines[] = sprintf("  - IP: %s | User: %s | Attempts: %d | First: %s | Last: %s", 
                    $bf['ip'], $bf['username'], $bf['attempts'], $this->ts((int)$bf['first_seen']), $this->ts((int)$bf['last_seen']));
            }
        }

        if (!empty($critical)) {
            $lines[] = "\nCRITICAL ACCESS EVENTS (Details):";
            $lines[] = "| Timestamp | Level | User | IP | Proto | Event |";
            $lines[] = "|---|---|---|---|---|---|";
            foreach ($critical as $c) {
                $lines[] = sprintf("| %s | %s | %s | %s | %s | %s |",
                    $this->ts((int)$c['time']), $c['level'], $c['username'], $c['ip'], $c['protocol'], $c['msg']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format disk events with summary + detailed table
     *
     * Drive error summary: per-drive event counts by level
     * Detailed logs: timestamp, slot, serial, error type, info
     * Source: SYNODISKDB (per-drive event history)
     *
     * @param array<string,mixed> $data {drive_summary, events}
     *
     * @return string Markdown with summary + detail table
     */
    public function formatDiskEvents(array $data): string
    {
        $summary = $data['drive_summary'] ?? [];
        $events = $data['events'] ?? [];

        $lines = [
            "## [SYNODISKDB] Disk-Level Hardware Events",
            "DRIVE ERROR SUMMARY (Last 12 Months):",
        ];

        foreach ($summary as $s) {
            $lines[] = sprintf("  - Drive %s (Slot %s): %d %s %s events", 
                $s['serial'], $s['slot'], $s['cnt'], $s['level'], $s['msg']);
        }

        if (!empty($events)) {
            $lines[] = "\nDETAILED HARDWARE LOGS:";
            $lines[] = "| Timestamp | Level | Slot | Serial | Event | Detail |";
            $lines[] = "|---|---|---|---|---|---|";
            foreach ($events as $e) {
                $lines[] = sprintf("| %s | %s | %s | %s | %s | %s |",
                    $this->ts((int)$e['time']), $e['level'], $e['slot'], $e['serial'], $e['msg'], $e['errtype'] . " " . $e['info']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Format btrfs scrub history and data integrity status
     *
     * Shows per-volume scrub completion dates and status
     * Gracefully handles missing/disabled scrub data
     * Identifies volumes with stale or failed scrubs
     *
     * @param array<string,mixed> $data {volume => {last_status, last_finished, detail}}
     *
     * @return string Markdown section
     */
    public function formatBtrfsScrub(array $data): string
    {
        if (empty($data) || isset($data['error'])) {
            return "## [SYNORSYNC] Btrfs Scrub: No history or scrub not enabled.";
        }

        $lines = ["## [SYNORSYNC] Btrfs Scrub & Data Integrity History"];
        foreach ($data as $volume => $stats) {
            $lines[] = sprintf("Volume %s: %s | Last Finished: %s | Status: %s",
                $volume,
                $stats['last_status'] ?? 'Unknown',
                $stats['last_finished'] ?? 'Never',
                $stats['detail'] ?? 'N/A'
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Format system load profile as JSON
     *
     * Combines three high-value system metrics:
     * - system_load: Load averages (1/5/15 min), CPU breakdown
     * - disk_io: Average latency per device
     * - memory_util: Swap, OOM risk, pressure metrics
     *
     * Dense data: best as JSON for LLM parsing
     *
     * @param array<string,mixed> $data {system_load, disk_io, memory_util}
     *
     * @return string Prettified JSON
     */
    public function formatLoadProfile(array $data): string
    {
        return json_encode([
            'load' => $data['system_load'] ?? [],
            'io_pressure' => $data['disk_io'] ?? [],
            'ram' => $data['memory_util'] ?? []
        ], JSON_PRETTY_PRINT);
    }
}
