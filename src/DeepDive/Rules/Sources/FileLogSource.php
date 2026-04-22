<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Rules\LogRecord;

/**
 * Thin SourceStream backed by one or more plaintext log files.
 *
 * Per-file year anchor: before yielding a file's records we scan its
 * head for an ISO-8601 timestamp (authoritative year) and fall back to
 * the file's mtime year otherwise. The TimestampParser is then reset
 * to that anchor so RFC3164 lines stamp with a plausible year instead
 * of `date('Y')`, which would otherwise make all historical events
 * look like they happened today.
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
