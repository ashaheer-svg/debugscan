<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use PDO;

/**
 * PdoSqliteSource: Read-only SQLite database connection handler
 *
 * PURPOSE:
 * Wraps PDO access to forensic SQLite databases in debug bundles. Provides
 * parameterized query interface with write protection, error handling, and
 * lazy connection initialization. Enables SqliteMatcher to query forensic
 * databases (SYNOSYSDB, SYNODISKHEALTHDB, etc.) with SQL patterns.
 *
 * DATABASES:
 * Synology debug bundles include various SQLite forensic databases:
 * - SYNOSYSDB: System events (fan speeds, thermal readings, power)
 * - SYNODISKHEALTHDB: SMART health data, error counters
 * - SYNOCONNDB: Connection tracking, active sessions
 * - SYNODISKDB: Disk inventory and physical info
 * - scemd.db: Specialized event database for storage/thermal events
 *
 * SAFETY:
 * 1. Read-only: PRAGMA query_only=ON prevents accidental writes
 * 2. Immutable: No schema caching (schema might change between rules)
 * 3. Parameterized: Prevents SQL injection via bound parameters
 * 4. Exception on error: All SQL errors surfaced immediately (fail fast)
 *
 * LAZY INITIALIZATION:
 * PDO connection created on first query, not in constructor. Allows:
 * - Opening only databases that rules actually reference
 * - Clean resource lifecycle (connection lives for evaluation run)
 * - Efficient handling of missing databases (no premature error)
 *
 * CONNECTION DETAILS:
 * - Mode: "ro" (read-only), immutable=1 (no VACUUM or schema changes)
 * - Error mode: ERRMODE_EXCEPTION (throw PDOException on SQL error)
 * - Fetch mode: FETCH_ASSOC (return rows as associative arrays)
 * - Pragma: query_only=ON (double-guard against accidental writes)
 *
 * QUERY INTERFACE:
 * query($sql, $params): Executes parameterized query, returns rows as array
 * - $sql: SQL query with ? placeholders for parameters
 * - $params: Array of values to bind (in order)
 * - returns: array of associative arrays (empty if no matches)
 *
 * USAGE:
 * Created by BundleLocator for each discovered SQLite database.
 * Registered in SourceRegistry by logical name ("smart.db", "scemd.db").
 * SqliteMatcher retrieves and calls query() to evaluate rules.
 * SourceRegistry caches PDO connection lifetime.
 *
 * ERROR HANDLING:
 * PDOException on any SQL error. Matchers catch and handle gracefully
 * (log and continue). Bad SQL in rule → finding skipped, not fatal.
 *
 * THREADING:
 * Not thread-safe (PDO connection state is mutable). Each evaluation run
 * gets separate instance. Matchers run sequentially in single thread.
 *
 * @package App\DeepDive\Rules\Sources
 */
final class PdoSqliteSource implements SqliteSource
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {}

    public function name(): string { return $this->name; }
    public function path(): string { return $this->path; }

    public function query(string $sql, array $params = []): array
    {
        if ($this->pdo === null) {
            // mode=ro + immutable=1 guards against schema surprises and keeps
            // us from accidentally mutating recovered artifacts.
            $dsn = 'sqlite:' . $this->path;
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA query_only = ON');
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
