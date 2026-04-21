<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use App\DeepDive\Rules\LogRecord;

/**
 * A logical source of LogRecords — could be backed by a single log file,
 * a family of rotated files (messages, messages.1, messages.2...), or
 * an in-memory synthesised event stream produced by a parser.
 *
 * Streams are *re-iterable*: the evaluator may scan the same source with
 * multiple rules. Implementations MUST yield a fresh iterator each call
 * so rules don't interfere with each other.
 */
interface SourceStream
{
    /** Stable logical name — e.g. "messages", "kern.log", "scemd.log". */
    public function name(): string;

    /**
     * Fresh iterator of LogRecord objects.
     *
     * @return iterable<LogRecord>
     */
    public function records(): iterable;
}
