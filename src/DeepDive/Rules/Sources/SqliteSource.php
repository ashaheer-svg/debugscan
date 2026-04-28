<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

/**
 * SqliteSource: Interface for forensic SQLite database access
 *
 * PURPOSE:
 * Defines contract for querying forensic SQLite databases in debug bundles.
 * Allows SqliteMatcher to run parameterized queries against databases
 * (SYNOSYSDB, SYNODISKHEALTHDB, etc.) without knowing database location
 * or connection details.
 *
 * DATABASES IN BUNDLES:
 * Synology debug bundles include various SQLite forensic databases:
 * - SYNOSYSDB: System events (thermal, fan, power)
 * - SYNODISKHEALTHDB: Disk SMART health, error counters
 * - SYNOCONNDB: Active network connections and sessions
 * - SYNODISKDB: Physical disk inventory and metadata
 * - scemd.db, synocrond.sqlite: Event and task logs
 * Paths vary by DSM version (6 vs 7, different base locations).
 *
 * IMPLEMENTATION:
 * Primary implementation is PdoSqliteSource: PDO-based read-only access.
 * Opens database with query_only pragma (no accidental writes).
 * Lazy initializes PDO connection on first query.
 *
 * INTERFACE METHODS:
 * 1. name(): Returns logical name ("smart.db", "scemd.db", etc.)
 *    Used by SourceRegistry to key the database.
 *
 * 2. path(): Returns absolute file path to .sqlite file
 *    For debugging and verification (is the file really there?)
 *
 * 3. query(sql, params): Execute parameterized SELECT query
 *    - sql: SQL query with ? placeholders
 *    - params: Array of values to bind (in order)
 *    - Returns: array of associative arrays (rows)
 *    - Throws: PDOException on SQL error or read failure
 *
 * PARAMETERIZED QUERIES:
 * SqliteMatcher constructs queries from rule signatures and binds values
 * via prepared statement parameters. Prevents SQL injection:
 * - Rule: SELECT * FROM events WHERE device_id = ?
 * - Matcher: query(sql, [123])
 * - Result: device_id=123 matched without risk of injection
 *
 * RETURN FORMAT:
 * query() returns array of associative arrays:
 * [
 *   {'id' => '1', 'status' => 'error', 'count' => '5'},
 *   {'id' => '2', 'status' => 'ok', 'count' => '0'},
 * ]
 * Matcher converts rows to LogRecord-like structures for uniformity.
 *
 * ERROR HANDLING:
 * query() throws PDOException on:
 * - SQL syntax errors
 * - File not readable
 * - Database locked
 * - Column not found
 * SqliteMatcher catches exceptions, logs, returns [] (no findings).
 * Bad rule SQL → finding skipped, doesn't break pipeline.
 *
 * READ-ONLY OPERATIONS:
 * All operations SELECT only. Implementations enforce:
 * - PRAGMA query_only = ON (double-guard against mutations)
 * - Error on INSERT, UPDATE, DELETE, DROP
 * - Safe for analyzing recovered databases without risk
 *
 * REGISTRY USAGE:
 * 1. BundleLocator discovers databases in bundle
 * 2. Creates PdoSqliteSource for each
 * 3. Registers in SourceRegistry by name
 * 4. SqliteMatcher queries via registry.sqlite(name)
 * 5. Matcher executes rules' SQL patterns
 *
 * PERFORMANCE:
 * PdoSqliteSource lazily opens database (first query).
 * Connection reused for multiple rule queries in same evaluation.
 * No connection pooling (single connection per database per job).
 *
 * @package App\DeepDive\Rules\Sources
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
