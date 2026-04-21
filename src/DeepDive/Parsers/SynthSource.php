<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

use App\DeepDive\Rules\LogRecord;
use App\DeepDive\Rules\Sources\SourceStream;

/**
 * Synthetic SourceStream backed by an in-memory list of LogRecords.
 * Used by snapshot parsers that turn non-line-oriented files (mdstat, df,
 * top, vmstat, btrfs check output) into rule-addressable event streams.
 */
final class SynthSource implements SourceStream
{
    /**
     * @param list<LogRecord> $records
     */
    public function __construct(
        private readonly string $name,
        private readonly array  $records,
    ) {}

    public function name(): string { return $this->name; }

    public function records(): iterable
    {
        foreach ($this->records as $r) yield $r;
    }
}
