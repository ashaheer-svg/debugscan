<?php

declare(strict_types=1);

namespace App\Services;

class PackagingService
{
    /**
     * Convert Unix timestamp to readable string.
     */
    private function ts(int $unix): string
    {
        return date('Y-m-d H:i:s', $unix);
    }

    /**
     * Format B: Markdown Table for System Events.
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
     * Format A: Raw JSON for Disk Health.
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
     * Format C: Tiered for Connections.
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
     * Format C: Tiered for Disk Events.
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
     * Format D: Btrfs Scrub History & Data Integrity.
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
     * Format E: High-Density IO & Load Profile.
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
// Final Sync - Corrected Namespace
