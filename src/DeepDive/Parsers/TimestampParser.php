<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

/**
 * TimestampParser: Flexible log timestamp extraction and normalization
 *
 * PURPOSE:
 * Extracts timestamps from diverse log line formats and normalizes to
 * ISO-8601. Handles real-world complexity: multiple formats in single file,
 * missing years, year rollovers mid-file. Used by FileLogSource to parse
 * timestamps during log iteration.
 *
 * SUPPORTED FORMATS:
 * 1. RFC3164 (syslog):   "Apr 21 09:12:03 host kernel: ..." (no year!)
 * 2. ISO-8601:          "2026-04-21T09:12:03.000+0000 ..."
 * 3. DSM scemd:         "2026-04-21 09:12:03 ..."
 * 4. dmesg (kernel):    "[  123.456789] ..." (monotonic, not wall clock)
 * 5. Unrecognized:      null (line doesn't start with recognizable timestamp)
 *
 * RETURN FORMAT:
 * Normalized ISO-8601 string (YYYY-MM-DDTHH:MM:SS) for matchers and
 * correlation logic. Milliseconds/timezone info stripped for simplicity
 * (sufficient precision for finding age-based filtering).
 *
 * THE YEAR PROBLEM:
 * RFC3164 timestamps omit year. "Apr 21 09:12:03" could be:
 * - Current year (normal case)
 * - Previous year (log rotation didn't happen)
 * - Next year (if analyzing future logs)
 *
 * Solution: BundleLocator infers year from:
 * 1. First ISO-8601 timestamp in file (authoritative)
 * 2. File mtime year (when bundle was created)
 * 3. Current year (last resort)
 * Then FileLogSource calls resetTo(year) before processing file.
 *
 * YEAR ROLLOVER DETECTION:
 * RFC3164-only files (no ISO timestamps) can span Dec→Jan boundary.
 * Parser tracks lastMonth: if it sees Dec (12) followed by Jan (1),
 * assumes year rollover and increments anchorYear.
 * Heuristic: only bump on large month delta (>=6) to avoid spurious
 * detections from out-of-order lines.
 *
 * MIXED FORMAT HANDLING:
 * Single file may contain both RFC3164 and ISO-8601 lines (common in
 * rotated logs). When ISO line encountered mid-stream, it's "authoritative"
 * and realigns anchor year. Subsequent RFC3164 lines use corrected year.
 * This handles: "old RFC3164 stuff" + "recently added ISO-8601" seamlessly.
 *
 * STATE MANAGEMENT:
 * - anchorYear: Year to apply to RFC3164 timestamps (reset per file)
 * - lastMonth: Previous line's month (for rollover detection)
 * Call resetTo(year) at start of each new file to reset state.
 *
 * USAGE:
 * FileLogSource creates TimestampParser(defaultYear), resets per file,
 * calls parse(line) for each line. Matcher receives ISO-8601 timestamp
 * and uses for age filtering ("findings older than 365 days").
 *
 * @package App\DeepDive\Parsers
 */
final class TimestampParser
{
    private const MONTH = [
        'Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
        'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12,
    ];

    private int  $anchorYear;
    private ?int $lastMonth = null;

    public function __construct(int $defaultYear)
    {
        $this->anchorYear = $defaultYear;
    }

    /** Reset anchor year + rollover tracker. Call at the start of each new file. */
    public function resetTo(int $year): void
    {
        $this->anchorYear = $year;
        $this->lastMonth  = null;
    }

    public function anchorYear(): int { return $this->anchorYear; }

    public function parse(string $line): ?string
    {
        // ISO-8601 / DSM "2024-12-15 10:30:45" — authoritative, realigns anchor.
        if (preg_match(
            '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?/',
            $line, $m
        )) {
            $this->anchorYear = (int)$m[1];
            $this->lastMonth  = (int)$m[2];
            return sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
        }

        // RFC3164 — "Apr 21 09:12:03"
        if (preg_match(
            '/^(?<mon>[A-Z][a-z]{2})\s+(?<d>\d{1,2})\s+(?<h>\d{2}):(?<mi>\d{2}):(?<s>\d{2})/',
            $line, $m
        )) {
            $mon = self::MONTH[$m['mon']] ?? null;
            if ($mon === null) return null;

            // Year-rollover heuristic: a file written chronologically that
            // steps from a late month (>=7) to an early one (<=3) has wrapped.
            // The >=6 delta guard stops spurious bumps from out-of-order lines.
            if ($this->lastMonth !== null
                && $mon < $this->lastMonth
                && ($this->lastMonth - $mon) >= 6
            ) {
                $this->anchorYear++;
            }
            $this->lastMonth = $mon;

            return sprintf(
                '%04d-%02d-%02dT%02d:%02d:%02d',
                $this->anchorYear, $mon, (int)$m['d'], (int)$m['h'], (int)$m['mi'], (int)$m['s']
            );
        }

        // dmesg monotonic "[  123.456]" — keep flagged so callers can handle.
        if (preg_match('/^\[\s*(\d+\.\d+)\]/', $line, $m)) {
            return 'MONO+' . $m[1];
        }
        return null;
    }
}
