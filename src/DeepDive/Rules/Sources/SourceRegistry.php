<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

/**
 * Registry of all logical sources for one evaluation run. Parsers populate
 * this during the Parse step; the Evaluate step reads from it.
 *
 * Rules reference sources by logical name (e.g. "messages", "scemd.db").
 * This layer lets us swap physical backings (rotated files, synthesised
 * streams) without touching rule YAML.
 */
final class SourceRegistry
{
    /** @var array<string,SourceStream> */
    private array $logs = [];
    /** @var array<string,SqliteSource> */
    private array $sqlite = [];

    public function registerLog(SourceStream $src): void
    {
        $this->logs[$src->name()] = $src;
    }

    public function registerSqlite(SqliteSource $src): void
    {
        $this->sqlite[$src->name()] = $src;
    }

    public function log(string $name): ?SourceStream
    {
        return $this->logs[$name] ?? null;
    }

    public function sqlite(string $name): ?SqliteSource
    {
        return $this->sqlite[$name] ?? null;
    }

    /** @return array<string,SourceStream> */
    public function allLogs(): array { return $this->logs; }

    /** @return array<string,SqliteSource> */
    public function allSqlite(): array { return $this->sqlite; }
}
