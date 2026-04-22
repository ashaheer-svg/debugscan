<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

/**
 * Extracts the leading timestamp from common log line formats and returns
 * it as an ISO-8601 string (or null if unrecognised).
 *
 * Supports:
 *   - RFC3164 syslog:  "Apr 21 09:12:03 host daemon: ..."
 *   - ISO-8601:        "2026-04-21T09:12:03.000+0000 ..."
 *   - DSM scemd:       "2026-04-21 09:12:03 ..."
 *   - dmesg kernel:    "[  123.456789] ..."   (kept as monotonic)
 *
 * RFC3164 has no year. The parser keeps a per-file anchor year that
 * callers prime via resetTo() before iterating a file. When the parser
 * sees an ISO line mid-stream it realigns the anchor to that year so
 * files with mixed formats Just Work. It also detects year rollover
 * (Dec → Jan) inside an RFC3164-only stream and bumps the anchor
 * automatically.
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
