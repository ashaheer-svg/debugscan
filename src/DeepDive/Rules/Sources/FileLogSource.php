<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use App\DeepDive\Rules\LogRecord;

/**
 * Thin SourceStream backed by one or more plaintext log files.
 * Used by parsers when no higher-level structuring is needed.
 *
 * Timestamp extraction is the parser's job — pass a pre-built list of
 * (file, lineNumber, text, timestamp) or let callers subclass.
 */
final class FileLogSource implements SourceStream
{
    /**
     * @param string        $name  logical source name
     * @param list<string>  $paths ordered list of absolute file paths (e.g. rotated logs)
     * @param \Closure      $tsExtractor function(string $line): ?string — returns ISO-8601 or null
     */
    public function __construct(
        private readonly string   $name,
        private readonly array    $paths,
        private readonly \Closure $tsExtractor,
    ) {}

    public function name(): string { return $this->name; }

    public function records(): iterable
    {
        foreach ($this->paths as $path) {
            if (!is_file($path) || !is_readable($path)) continue;

            $fh = @fopen($path, 'rb');
            if ($fh === false) continue;

            $lineNo = 0;
            try {
                while (($line = fgets($fh)) !== false) {
                    $lineNo++;
                    $trimmed = rtrim($line, "\r\n");
                    if ($trimmed === '') continue;
                    $ts = ($this->tsExtractor)($trimmed);
                    yield new LogRecord($path, $lineNo, $ts, $trimmed);
                }
            } finally {
                fclose($fh);
            }
        }
    }
}
