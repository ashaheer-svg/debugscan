<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

/**
 * A SQLite database recovered from the bundle (e.g. synocrond.sqlite,
 * scemd.db). Rules query it via prepared statements.
 */
interface SqliteSource
{
    public function name(): string;

    /** Absolute path to the .sqlite file on disk. */
    public function path(): string;

    /**
     * Run a read-only SELECT and return all rows as associative arrays.
     *
     * @param array<string,scalar|null> $params
     * @return array<int,array<string,mixed>>
     */
    public function query(string $sql, array $params = []): array;
}
