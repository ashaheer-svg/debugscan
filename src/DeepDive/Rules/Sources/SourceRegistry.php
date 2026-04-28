<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

/**
 * SourceRegistry: Centralized data source location and access service
 *
 * PURPOSE:
 * Provides stable, logical interface to all available data sources in
 * evaluation context. Parsers and BundleLocator register sources during
 * Parse step. Matchers and rules query registry by logical name during
 * Evaluate step. Decouples rule authoring from physical data layout.
 *
 * DESIGN:
 * Single registry instance per job. Populated once (during Parse), read
 * many times (Evaluate). Two registries: logs and SQLite databases.
 * Both use same pattern: register by logical name, query by logical name.
 *
 * TWO TYPES OF SOURCES:
 * 1. Log sources (SourceStream): Text logs with record iteration
 *    - FileLogSource: Real log files ("messages", "auth.log")
 *    - SynthSource: Synthetic logs from /proc snapshots ("mdstat", "df")
 *    - Both implement SourceStream: name() and records() iterator
 *
 * 2. SQLite sources (SqliteSource): Database query interface
 *    - PdoSqliteSource: Real SQLite files ("smart.db", "scemd.db")
 *    - Both implement SqliteSource: name(), path(), query()
 *
 * LOGICAL NAMES (Rule Interface):
 * Rules reference sources by logical name (stable across bundle variants):
 * - "messages": Main system log (may be messages or messages.1 + messages.2)
 * - "kern.log": Kernel log (may be kern.log, kern.log.1, etc.)
 * - "mdstat": RAID array state (synthetic from /proc/mdstat)
 * - "scemd.db": Event database (path varies by DSM version)
 *
 * ABSTRACTION BENEFIT:
 * Physical layout changes don't break rules. If DSM 7 moves logs from
 * /var/log/ to /var/log/synolog/, only BundleLocator changes. Rules
 * stay identical: still reference "messages" by name.
 *
 * USAGE FLOW:
 * 1. ParseStep: BundleLocator.locate() walks bundle, creates sources
 * 2. ParseStep: Sources registered in registry (log + sqlite)
 * 3. EvaluateStep: Matchers call registry.log("messages") or .sqlite("smart.db")
 * 4. Matchers: Iterate through records/rows returned by source
 * 5. Pipeline context: registry passed to all matchers
 *
 * QUERYING:
 * - registry.log(name): Get SourceStream by name (or null)
 * - registry.sqlite(name): Get SqliteSource by name (or null)
 * - registry.allLogs(): Get all registered log sources (for debugging)
 * - registry.allSqlite(): Get all registered SQLite sources
 *
 * MISSING SOURCE HANDLING:
 * Returns null if source not found. Matchers handle gracefully:
 * - RegexMatcher: null source → return [] (no matches)
 * - SqliteMatcher: null source → return [] (no rows)
 * - Absence matcher: null source → skip (can't assert absence without evidence)
 *
 * THREAD SAFETY:
 * Not thread-safe (populated once, then read-only). Single instance per
 * job evaluation. If parallel evaluation needed, create separate registries.
 *
 * @package App\DeepDive\Rules\Sources
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
