<?php

declare(strict_types=1);

namespace App\Helpers;

use SQLite3;
use RuntimeException;
use Exception;

/**
 * Secure, read-only wrapper for Synology's internal SQLite databases.
 * Includes safety caps for row results to prevent memory exhaustion.
 */
class SqliteReader
{
    /**
     * Executes a query on a specified database file with a mandatory row limit.
     * 
     * @param string $dbPath Absolute path to the .db file
     * @param string $query  SQL query to execute
     * @param int    $limit  Maximum number of rows to return
     * @return array List of associative arrays for each row
     * @throws RuntimeException
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
