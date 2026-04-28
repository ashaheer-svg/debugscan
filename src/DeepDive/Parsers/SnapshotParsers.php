<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

use App\DeepDive\Rules\LogRecord;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * SnapshotParsers: Convert /proc snapshots to synthetic log streams
 *
 * PURPOSE:
 * Adapts point-in-time system snapshots (mdstat, df, vmstat, top, btrfs) into
 * synthetic log record streams. This allows regex and aggregate matchers to
 * address fact snapshots uniformly using the same interface as time-series
 * logs. Rule authors write rules against "mdstat" without knowing whether
 * they're processing 100 log lines or a single /proc/mdstat snapshot.
 *
 * MOTIVATION:
 * Logs have natural record boundaries (one line = one event). Snapshots don't:
 * /proc/mdstat is one multi-line file. SnapshotParsers assigns logical record
 * boundaries (e.g. one LogRecord per md array) and normalizes output to match
 * LogRecord structure {file, lineNumber, timestamp, text, extra}.
 *
 * SNAPSHOT SOURCES AND OUTPUT:
 * 1. mdstat:      One LogRecord per RAID array (header line and status details)
 *                 Text: "array=md2 state=clean level=raid5 devices=[sda3,sdb3,sdc3]"
 * 2. df:          One LogRecord per mounted filesystem (parsed from df output)
 *                 Text: "mount=/volume1 used=500GB total=1000GB usage=50%"
 * 3. vmstat:      One LogRecord per data row (memory page activity summary)
 *                 Text: "page_in=1000 page_out=500 swap_in=10 swap_out=5"
 * 4. btrfs_result: One LogRecord per btrfs filesystem status line
 *                 Text: "filesystem=btrfs1 status=ok errors=0"
 * 5. top_snapshot: One LogRecord per top process row (load averages and top processes)
 *                 Text: "load1=0.5 load5=0.3 load15=0.2 process=systemd cpu=0.1 mem=1.2"
 *
 * REGISTRATION:
 * Each parser checks for its input file in multiple locations (DSM 6 vs 7
 * path variations). If found, creates a SynthSource object and registers it
 * in SourceRegistry. Missing snapshots are silently skipped (not all bundles
 * contain all snapshots).
 *
 * LOG RECORD STRUCTURE:
 * Each synthetic record mimics real logs:
 * - file: "mdstat", "df", "vmstat", etc. (for citation purposes)
 * - lineNumber: Logical record number (1-indexed)
 * - timestamp: null (snapshots are point-in-time, not timestamped)
 * - text: Normalized key=value text for matchers
 * - extra: Parser-specific metadata (e.g. {device: "md2", state: "clean"})
 *
 * SYNTHETIC SOURCE CONTAINER:
 * SynthSource is a FileLogSource that holds pre-parsed LogRecord[] instead of
 * reading from disk. Allows rules to reference snapshot data exactly like
 * log data. Matchers iterate through synthetic records without knowing origin.
 *
 * NORMALIZATION:
 * Snapshot formats vary by DSM version and tool. Parsers normalize to
 * consistent key=value format for rule matching: "key1=val1 key2=val2 ..."
 * This allows rules to use regex patterns like /usage=(\d+)%/ or /state=degraded/
 *
 * @package App\DeepDive\Parsers
 */
final class SnapshotParsers
{
    public static function register(string $root, SourceRegistry $reg): void
    {
        $mdstat = self::findFirst($root, ['proc/mdstat', 'mdstat']);
        if ($mdstat !== null) {
            $reg->registerLog(new SynthSource('mdstat', self::parseMdstat($mdstat)));
        }

        $df = self::findFirst($root, ['df.out', 'proc/df', 'df']);
        if ($df !== null) {
            $reg->registerLog(new SynthSource('df', self::parseDf($df)));
        }

        $vmstat = self::findFirst($root, ['proc/vmstat', 'vmstat.out', 'vmstat']);
        if ($vmstat !== null) {
            $reg->registerLog(new SynthSource('vmstat', self::parseVmstat($vmstat)));
        }

        $btrfs = self::findFirst($root, ['btrfs.check.out', 'btrfs_check.log']);
        if ($btrfs !== null) {
            $reg->registerLog(new SynthSource('btrfs_result', self::parseBtrfs($btrfs)));
        }

        $top = self::findFirst($root, ['top.out', 'proc/top']);
        if ($top !== null) {
            $reg->registerLog(new SynthSource('top_snapshot', self::parseTop($top)));
        }
    }

    /** @param list<string> $rels */
    private static function findFirst(string $root, array $rels): ?string
    {
        foreach ($rels as $r) {
            $p = $root . '/' . $r;
            if (is_file($p) && is_readable($p)) return $p;
        }
        return null;
    }

    /** /proc/mdstat — emits one LogRecord per active md device. */
    private static function parseMdstat(string $path): array
    {
        $txt = (string)@file_get_contents($path);
        $records = [];
        $lineNo = 0;
        $current = null;

        foreach (explode("\n", $txt) as $line) {
            $lineNo++;
            // Header line: "md2 : active raid5 sda3[0] sdb3[1] sdc3[2]"
            if (preg_match('/^(md\d+)\s*:\s*(\S+)\s+(\S+)\s+(.*)$/', $line, $m)) {
                if ($current !== null) $records[] = $current;
                $current = new LogRecord(
                    file: $path,
                    lineNumber: $lineNo,
                    timestamp: null,
                    text: sprintf('array=%s state=%s level=%s members=%s', $m[1], $m[2], $m[3], trim($m[4])),
                    extra: ['array' => $m[1], 'state' => $m[2], 'level' => $m[3]],
                );
            } elseif ($current !== null && preg_match('/\[([_U]+)\]/', $line, $m)) {
                // Status line: "[UU_]"  — underscore means missing disk
                $missing = substr_count($m[1], '_');
                $current = new LogRecord(
                    $current->file, $current->lineNumber, null,
                    $current->text . sprintf(' status=%s missing=%d', $m[1], $missing),
                    array_merge($current->extra, ['status' => $m[1], 'missing' => $missing]),
                );
            } elseif ($current !== null && preg_match('/(recovery|resync|reshape)\s*=\s*([\d.]+)%/i', $line, $m)) {
                $current = new LogRecord(
                    $current->file, $current->lineNumber, null,
                    $current->text . sprintf(' op=%s progress=%s%%', strtolower($m[1]), $m[2]),
                    array_merge($current->extra, ['op' => strtolower($m[1]), 'progress' => $m[2]]),
                );
            }
        }
        if ($current !== null) $records[] = $current;
        return $records;
    }

    /** df snapshot — emits one LogRecord per mountpoint. */
    private static function parseDf(string $path): array
    {
        $records = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) return [];
        $lineNo = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                $line = rtrim($line);
                if ($lineNo === 1 && stripos($line, 'filesystem') !== false) continue;
                // Filesystem Size Used Avail Use% Mounted
                if (preg_match('/^\S+\s+\S+\s+\S+\s+\S+\s+(\d+)%\s+(\S.*)$/', $line, $m)) {
                    $pct = (int)$m[1];
                    $mnt = trim($m[2]);
                    $records[] = new LogRecord(
                        file: $path,
                        lineNumber: $lineNo,
                        timestamp: null,
                        text: sprintf('mount=%s use=%d%%', $mnt, $pct),
                        extra: ['mount' => $mnt, 'use_pct' => $pct],
                    );
                }
            }
        } finally {
            fclose($fh);
        }
        return $records;
    }

    /** /proc/vmstat — one record per counter:value pair (bounded). */
    private static function parseVmstat(string $path): array
    {
        $records = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) return [];
        $lineNo = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                $line = rtrim($line);
                if ($line === '') continue;
                if (preg_match('/^(\S+)\s+(\d+)$/', $line, $m)) {
                    $records[] = new LogRecord(
                        file: $path,
                        lineNumber: $lineNo,
                        timestamp: null,
                        text: sprintf('counter=%s value=%d', $m[1], (int)$m[2]),
                        extra: ['counter' => $m[1], 'value' => (int)$m[2]],
                    );
                }
                if (count($records) > 500) break; // vmstat has ~150 counters; 500 is a safe cap
            }
        } finally {
            fclose($fh);
        }
        return $records;
    }

    /** btrfs check output — emits one record per non-empty line, flagging error/warning prefixes. */
    private static function parseBtrfs(string $path): array
    {
        $records = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) return [];
        $lineNo = 0;
        try {
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                $t = rtrim($line);
                if ($t === '') continue;
                $level = match (true) {
                    (bool)preg_match('/^\s*(ERROR|error|failed)/', $t)   => 'error',
                    (bool)preg_match('/^\s*(WARNING|warning|warn)/', $t) => 'warning',
                    default                                              => 'info',
                };
                $records[] = new LogRecord(
                    file: $path,
                    lineNumber: $lineNo,
                    timestamp: null,
                    text: sprintf('level=%s msg=%s', $level, $t),
                    extra: ['level' => $level],
                );
                if (count($records) > 2000) break;
            }
        } finally {
            fclose($fh);
        }
        return $records;
    }

    /** top snapshot — top-N rows. Columns vary by DSM version; we record text verbatim plus a header cue. */
    private static function parseTop(string $path): array
    {
        $records = [];
        $fh = @fopen($path, 'rb');
        if ($fh === false) return [];
        $lineNo = 0;
        $inBody = false;
        try {
            while (($line = fgets($fh)) !== false) {
                $lineNo++;
                $t = rtrim($line);
                if ($t === '') continue;
                if (!$inBody && preg_match('/^\s*PID\s+USER/', $t)) { $inBody = true; continue; }
                if (!$inBody) {
                    if (preg_match('/load average:\s*([\d.]+),\s*([\d.]+),\s*([\d.]+)/', $t, $m)) {
                        $records[] = new LogRecord(
                            $path, $lineNo, null,
                            sprintf('kind=loadavg l1=%s l5=%s l15=%s', $m[1], $m[2], $m[3]),
                            ['kind' => 'loadavg', 'l1' => (float)$m[1], 'l5' => (float)$m[2], 'l15' => (float)$m[3]],
                        );
                    }
                    continue;
                }
                if (preg_match('/^\s*(\d+)\s+(\S+)\s+.*?\s(\d+\.\d+)\s+(\d+\.\d+)\s+.*?\s+(\S+)\s*$/', $t, $m)) {
                    $records[] = new LogRecord(
                        $path, $lineNo, null,
                        sprintf('kind=proc pid=%s user=%s cpu=%s mem=%s cmd=%s', $m[1], $m[2], $m[3], $m[4], $m[5]),
                        [
                            'kind' => 'proc', 'pid' => (int)$m[1], 'user' => $m[2],
                            'cpu' => (float)$m[3], 'mem' => (float)$m[4], 'cmd' => $m[5],
                        ],
                    );
                }
                if (count($records) > 200) break;
            }
        } finally {
            fclose($fh);
        }
        return $records;
    }
}
