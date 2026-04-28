<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Rules\LogRecord;

/**
 * FileLogSource: SourceStream backed by plaintext log files
 *
 * PURPOSE:
 * Streams LogRecord objects from one or more plaintext log files. Used by
 * BundleLocator to wrap bundled logs ("messages", "auth.log", "kern.log", etc.).
 * Handles file I/O, line parsing, timestamp extraction, and multi-file rotation
 * consolidation seamlessly.
 *
 * MULTI-FILE SUPPORT (Log Rotation):
 * Handles rotated logs transparently: ["messages", "messages.1", "messages.2"]
 * are passed as single FileLogSource. Iterator processes files in order,
 * emitting records chronologically (oldest in messages.2, newest in messages).
 * Allows rules to match across rotation boundaries without special handling.
 *
 * TIMESTAMP ANCHORING (Year Inference):
 * Log timestamps often lack year (e.g. syslog: "Apr 28 10:23:45"). Without
 * year context, same timestamp could be parsed as today or 30 years ago.
 * FileLogSource infers year per-file:
 * 1. Scans first 500 lines for ISO-8601 date (authoritative year)
 * 2. Falls back to file mtime year (last modified timestamp)
 * 3. Final fallback to current year
 * Then resets TimestampParser to inferred year, so RFC3164 lines parse correctly.
 *
 * RECORD EMISSION:
 * Yields LogRecord for each non-empty line:
 * - file: Absolute path to log file
 * - lineNumber: 1-indexed line number in file
 * - timestamp: ISO-8601 string from TimestampParser, or null if unparseable
 * - text: Line content (trailing newlines stripped)
 * - extra: {} (empty, FileLogSource doesn't parse syslog structure)
 *
 * ERROR HANDLING:
 * Graceful degradation:
 * - Missing file: skipped (continue to next)
 * - Unreadable file: skipped with warning
 * - Read error mid-file: exception caught, file closed, continue
 * - Malformed lines: passed through as-is, timestamp might be null
 *
 * PERFORMANCE:
 * Lazy evaluation: records() returns Iterator, doesn't load entire file.
 * Files processed one at a time, lines streamed one by one. Matchers can
 * break early, stopping file I/O. Safe for large (GB) log files.
 *
 * USAGE:
 * Created by BundleLocator for each logical log source. Registered in
 * SourceRegistry. Matchers retrieve via reg.log("messages") and iterate
 * to collect matching records. Matcher only knows about SourceStream interface.
 *
 * @package App\DeepDive\Rules\Sources
 */
final class FileLogSource implements SourceStream
{
    /**
     * @param string             $name  logical source name
     * @param list<string>       $paths ordered list of absolute file paths (e.g. rotated logs)
     * @param TimestampParser    $tsp   shared parser; state is reset per file
     */
    public function __construct(
        private readonly string          $name,
        private readonly array           $paths,
        private readonly TimestampParser $tsp,
    ) {}

    public function name(): string { return $this->name; }

    public function records(): iterable
    {
        foreach ($this->paths as $path) {
            if (!is_file($path) || !is_readable($path)) continue;

            $this->tsp->resetTo($this->inferAnchorYear($path));

            $fh = @fopen($path, 'rb');
            if ($fh === false) continue;

            $lineNo = 0;
            try {
                while (($line = fgets($fh)) !== false) {
                    $lineNo++;
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed === '') continue;
                    $ts = $this->tsp->parse($trimmed);
                    yield new LogRecord($path, $lineNo, $ts, $trimmed);
                }
            } finally {
                fclose($fh);
            }
        }
    }

    /**
     * Find the year this file was written in. Priority:
     *   1. First ISO-8601 date line in the first 500 records (cheap, cache-friendly).
     *   2. File mtime year.
     *   3. Current year as last resort.
     */
    private function inferAnchorYear(string $path): int
    {
        $fh = @fopen($path, 'rb');
        if ($fh !== false) {
            try {
                for ($i = 0; $i < 500 && ($line = fgets($fh)) !== false; $i++) {
                    if (preg_match('/\b(\d{4})-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}/', $line, $m)) {
                        $y = (int)$m[1];
                        if ($y >= 2000 && $y <= 2100) return $y;
                    }
                }
            } finally {
                fclose($fh);
            }
        }
        $mt = @filemtime($path);
        if ($mt) return (int)date('Y', $mt);
        return (int)date('Y');
    }
}
