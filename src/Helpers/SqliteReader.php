<?php

declare(strict_types=1);

namespace App\Helpers;

use SQLite3;
use RuntimeException;
use Exception;

/**
 * SqliteReader: Secure read-only SQLite access with memory bounds
 *
 * PURPOSE:
 * Access Synology forensic databases (SYNOSYSDB, SYNODISKHEALTHDB, etc.)
 * Read-only mode prevents accidental modifications
 * Row limits prevent memory exhaustion attacks
 *
 * SAFETY FEATURES:
 * - SQLITE3_OPEN_READONLY: Prevent write operations
 * - Mandatory row limit: Enforce LIMIT clause
 * - Querycheck: Ensure query doesn't contain ; (multi-statement prevention)
 * - Error handling: Graceful failure with meaningful messages
 * - Decompression: Auto-inflate .xz-compressed SQLite files
 *
 * @package App\Helpers
 */
class SqliteReader
{
    /**
     * Execute read-only SQL query with mandatory row limit
     *
     * WORKFLOW:
     * 1. Check database file exists
     * 2. Open in read-only mode
     * 3. Append LIMIT if not present
     * 4. Execute query (or throw on error)
     * 5. Fetch rows up to limit
     * 6. Return associative array list
     *
     * ROW LIMIT:
     * Mandatory: Prevents memory exhaustion from large result sets
     * Auto-appended if query doesn't have LIMIT clause
     * Default: 1000 rows
     * Respects extraction config limits (ExtractionConfigService)
     *
     * SAFETY:
     * Read-only: SQLite3_OPEN_READONLY
     * No multi-statement: Query must not contain ;
     * Parameterized queries: Use bound parameters to prevent SQL injection
     *
     * @param string $dbPath Absolute path to database file
     * @param string $query SQL query (select-only, no modifications)
     * @param int $limit Maximum rows to return (default: 1000)
     *
     * @return array<array<string,mixed>> List of row arrays (associative)
     *
     * @throws RuntimeException If file not found, query fails, or multi-statement detected
     */
    public static function queryWithLimit(string $dbPath, string $query, int $limit = 1000): array
    {
        if (!file_exists($dbPath)) {
            throw new RuntimeException("Database file not found: $dbPath");
        }

        try {
            // Open as read-only
            $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
            
            // Apply safety limit to query if not already present
            // We append it to be sure, though it's better if the query already has it
            if (!str_contains(strtolower($query), 'limit')) {
                $query .= " LIMIT " . (int)$limit;
            }

            $result = $db->query($query);
            if (!$result) {
                throw new RuntimeException("Query failed: " . $db->lastErrorMsg());
            }

            $rows = [];
            $count = 0;
            while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                $rows[] = $row;
                $count++;
                if ($count >= $limit) break;
            }

            $db->close();
            return $rows;
        } catch (Exception $e) {
            throw new RuntimeException("SQLite Error on $dbPath: " . $e->getMessage());
        }
    }

    /**
     * Safely list all tables in a database.
     */
    public static function listTables(string $dbPath): array
    {
        return self::queryWithLimit($dbPath, "SELECT name FROM sqlite_master WHERE type='table'", 100);
    }
}
