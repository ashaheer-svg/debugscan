<?php

declare(strict_types=1);

namespace App\Parsers;

use PDO;
use Exception;

class DatabaseParser
{
    private string $extractPath;
    private int $cutoffUnix;
    private string $cutoffDate;
    private array $dbPaths = [];

    const TARGET_DBS = [
        '.SYNOSYSDB',
        '.SYNODISKHEALTHDB',
        '.SYNOCONNDB',
        '.SYNODISKDB'
    ];

    public function __construct(string $extractPath)
    {
        $this->extractPath = rtrim($extractPath, DIRECTORY_SEPARATOR);
        $this->cutoffUnix = time() - (365 * 86400);
        $this->cutoffDate = date('Y-m-d', $this->cutoffUnix);
        
        $this->locateDatabases();
    }

    private function locateDatabases(): void
    {
        $logDir = $this->extractPath . DIRECTORY_SEPARATOR . 'dsm' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'synolog';
        
        foreach (self::TARGET_DBS as $dbName) {
            $path = $logDir . DIRECTORY_SEPARATOR . $dbName;
            if (file_exists($path)) {
                $this->dbPaths[$dbName] = $path;
            }
        }
    }

    public function parseAll(): array
    {
        $results = [];
        
        foreach ($this->dbPaths as $name => $path) {
            try {
                switch ($name) {
                    case '.SYNOSYSDB':
                        $results['system_events'] = $this->parseSystemEvents($path);
                        break;
                    case '.SYNODISKHEALTHDB':
                        $results['disk_health'] = $this->parseDiskHealth($path);
                        break;
                    case '.SYNOCONNDB':
                        $results['connection_logs'] = $this->parseConnections($path);
                        break;
                    case '.SYNODISKDB':
                        $results['disk_events'] = $this->parseDiskEvents($path);
                        break;
                }
            } catch (Exception $e) {
                $results['errors'][] = "Failed to parse $name: " . $e->getMessage();
            }
        }
        
        return $results;
    }

    private function getPdo(string $path): PDO
    {
        $pdo = new PDO("sqlite:" . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function parseSystemEvents(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        // Get Stats
        $stats = $pdo->prepare("SELECT COUNT(*) as total, SUM(CASE WHEN level='err' THEN 1 ELSE 0 END) as errs, SUM(CASE WHEN level='warning' THEN 1 ELSE 0 END) as warns FROM logs WHERE time >= :cutoff");
        $stats->execute(['cutoff' => $this->cutoffUnix]);
        $summary = $stats->fetch();

        // Get Rows
        $stmt = $pdo->prepare("SELECT time, level, username, msg FROM logs WHERE time >= :cutoff ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        
        return [
            'summary' => $summary,
            'rows' => $stmt->fetchAll()
        ];
    }

    private function parseDiskHealth(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        // Lifetime error counters
        $errors = $pdo->query("SELECT * FROM disk_error ORDER BY BadSector DESC, UNC DESC")->fetchAll();
        
        // Predictions
        $stmt = $pdo->prepare("SELECT date, serial, model, slot, fail, ui_score, score, threshold, factors, msg FROM prediction WHERE date >= :cutoff ORDER BY date DESC");
        $stmt->execute(['cutoff' => $this->cutoffDate]);
        
        return [
            'disk_error' => $errors,
            'prediction' => $stmt->fetchAll()
        ];
    }

    private function parseConnections(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        // Summary
        $stmt = $pdo->prepare("SELECT protocol, level, COUNT(*) as cnt FROM logs WHERE time >= :cutoff GROUP BY protocol, level ORDER BY cnt DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $summaryGroups = $stmt->fetchAll();

        // Failed login aggregation
        $stmt = $pdo->prepare("SELECT ip, username, COUNT(*) as attempts, MIN(time) as first_seen, MAX(time) as last_seen FROM logs WHERE time >= :cutoff AND level = 'warning' AND msg LIKE '%failed%' GROUP BY ip, username HAVING attempts >= 3 ORDER BY attempts DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $bruteForce = $stmt->fetchAll();

        // Actual Errors/Warnings
        $stmt = $pdo->prepare("SELECT time, level, username, ip, protocol, msg FROM logs WHERE time >= :cutoff AND level IN ('warning', 'err') ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        
        return [
            'summary_groups' => $summaryGroups,
            'brute_force' => $bruteForce,
            'critical_events' => $stmt->fetchAll()
        ];
    }

    private function parseDiskEvents(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        // Summary of events by drive
        $stmt = $pdo->prepare("SELECT serial, model, slot, level, msg, COUNT(*) as cnt FROM logs WHERE time >= :cutoff AND level IN ('warning', 'err') GROUP BY serial, msg ORDER BY cnt DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $summary = $stmt->fetchAll();

        // Detailed events
        $stmt = $pdo->prepare("SELECT time, level, model, serial, slot, container, msg, errtype, info FROM logs WHERE time >= :cutoff AND level IN ('info', 'warning', 'err') ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        
        return [
            'drive_summary' => $summary,
            'events' => $stmt->fetchAll()
        ];
    }
}
