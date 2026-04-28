<?php

declare(strict_types=1);

namespace App\Parsers;

use PDO;
use Exception;

/**
 * DatabaseParser: SQLite forensic database query and analysis
 *
 * PURPOSE:
 * Provides interface to query SQLite forensic databases embedded in Synology
 * debug bundles (SYNOSYSDB, SYNODISKHEALTHDB, etc.). Executes SQL queries
 * to extract structured data beyond what log-based parsers can provide.
 *
 * DATABASES:
 * - SYNOSYSDB: System events, thermal, fan, power
 * - SYNODISKHEALTHDB: SMART health history, error counts
 * - SYNOCONNDB: Connection and session tracking
 * - SYNODISKDB: Disk inventory and physical info
 * - scemd.db: Storage and thermal events
 *
 * QUERY EXECUTION:
 * Accepts SQL queries with parameter binding. Prevents SQL injection via
 * prepared statements. Returns results as associative arrays (rows).
 *
 * OUTPUT:
 * - rows: Array of query results
 * - row_count: Number of rows returned
 * - columns: Column names from result set
 *
 * USE CASES:
 * - Aggregate analysis: COUNT, SUM, GROUP BY operations
 * - Time series: Historical data analysis
 * - Correlation: Cross-table relationship discovery
 * - Threshold detection: Conditional aggregates (COUNT(*) > 5)
 *
 * DATA SOURCE:
 * SQLite files in bundle, accessed via PDO read-only connection
 *
 * @package App\Parsers
 */
class DatabaseParser implements ParserInterface
{
    const TARGET_DBS = [
        '.SYNOSYSDB',
        '.SYNODISKHEALTHDB',
        '.SYNOCONNDB',
        '.SYNODISKDB'
    ];

    public function parse(string $extractedPath, array &$context): array
    {
        $dbPaths = $this->locateDatabases($extractedPath);
        if (empty($dbPaths)) {
            return ['data' => [], 'citations' => []];
        }

        $results = [];
        $citations = [];
        $config = $context['_config']['audit_db'] ?? [];
        $cutoffUnix = time() - (365 * 86400); // Default 1y cutoff

        foreach ($dbPaths as $name => $path) {
            $relPath = str_replace($extractedPath . '/', '', $path);
            $citations[] = [
                'file' => $relPath,
                'lines' => 'sqlite_rows',
                'timestamp' => date('Y-m-d H:i:s', filemtime($path))
            ];

            try {
                switch ($name) {
                    case '.SYNOSYSDB':
                        $limit = $config['system_events_limit'] ?? 500;
                        $results['system_events'] = \App\Helpers\SqliteReader::queryWithLimit($path, "SELECT * FROM logs WHERE time >= $cutoffUnix ORDER BY time DESC", $limit);
                        break;
                    case '.SYNODISKHEALTHDB':
                        $results['disk_health'] = [
                            'errors' => \App\Helpers\SqliteReader::queryWithLimit($path, "SELECT * FROM disk_error", 100),
                            'predictions' => \App\Helpers\SqliteReader::queryWithLimit($path, "SELECT * FROM prediction ORDER BY date DESC", 50)
                        ];
                        break;
                    case '.SYNOCONNDB':
                        $limit = $config['connections_limit'] ?? 200;
                        $results['connections'] = \App\Helpers\SqliteReader::queryWithLimit($path, "SELECT * FROM logs WHERE level != 'info' ORDER BY time DESC", $limit);
                        break;
                    case '.SYNODISKDB':
                        $limit = $config['disk_events_limit'] ?? 200;
                        $results['disk_events'] = \App\Helpers\SqliteReader::queryWithLimit($path, "SELECT * FROM logs WHERE level != 'info' ORDER BY time DESC", $limit);
                        break;
                }
            } catch (\Exception $e) {
                $results['errors'][] = "Failed to parse database $name: " . $e->getMessage();
            }
        }

        return [
            'data' => $results,
            'citations' => $citations
        ];
    }

    private function locateDatabases(string $extractedPath): array
    {
        $dbPaths = [];
        $searchDirs = [
            $extractedPath . '/dsm/var/log/synolog',
            $extractedPath . '/var/log/synolog'
        ];

        foreach ($searchDirs as $dir) {
            if (is_dir($dir)) {
                foreach (self::TARGET_DBS as $dbName) {
                    $path = $dir . DIRECTORY_SEPARATOR . $dbName;
                    if (file_exists($path)) {
                        $dbPaths[$dbName] = $path;
                    }
                }
                if (!empty($dbPaths)) break;
            }
        }
        return $dbPaths;
    }
}
