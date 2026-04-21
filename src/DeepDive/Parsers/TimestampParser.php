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
 *   - dmesg kernel:    "[  123.456789] ..."   (converted relative to boot; here kept as monotonic)
 *
 * Ambiguity note: RFC3164 timestamps lack a year. We infer the year from
 * the `$defaultYear` passed in (typically the bundle's collection year).
 */
final class TimestampParser
{
    private const MONTH = [
        'Jan'=>1,'Feb'=>2,'Mar'=>3,'Apr'=>4,'May'=>5,'Jun'=>6,
        'Jul'=>7,'Aug'=>8,'Sep'=>9,'Oct'=>10,'Nov'=>11,'Dec'=>12,
    ];

    public function __construct(private readonly int $defaultYear) {}

    public function parse(string $line): ?string
    {
        // ISO-8601 with optional fractional seconds and TZ
        if (preg_match('/^(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+\-]\d{2}:?\d{2})?)/', $line, $m)) {
            $ts = str_replace(' ', 'T', $m[1]);
            return $ts;
        }
        // RFC3164: "Apr 21 09:12:03"
        if (preg_match('/^(?<mon>[A-Z][a-z]{2})\s+(?<d>\d{1,2})\s+(?<h>\d{2}):(?<mi>\d{2}):(?<s>\d{2})/', $line, $m)) {
            $mon = self::MONTH[$m['mon']] ?? null;
            if ($mon === null) return null;
            return sprintf(
                '%04d-%02d-%02dT%02d:%02d:%02d',
                $this->defaultYear, $mon, (int)$m['d'], (int)$m['h'], (int)$m['mi'], (int)$m['s']
            );
        }
        // dmesg monotonic "[  123.456]"
        if (preg_match('/^\[\s*(\d+\.\d+)\]/', $line, $m)) {
            return 'MONO+' . $m[1]; // flagged so callers can treat specially if needed
        }
        return null;
    }
}
