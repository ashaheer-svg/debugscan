<?php

declare(strict_types=1);

namespace App\Parsers;

use PDO;
use Exception;

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
