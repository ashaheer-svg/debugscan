<?php

declare(strict_types=1);

namespace App\Parsers;

use PDO;
use Exception;

class DatabaseParser
{
    private string $extractPath;
    private int $cutoffUnix = 0;
    private string $cutoffDate = "";
    private array $dbPaths = [];
    private ?string $forensicRoot = null;

    const TARGET_DBS = [
        '.SYNOSYSDB',
        '.SYNODISKHEALTHDB',
        '.SYNOCONNDB',
        '.SYNODISKDB'
    ];

    /**
     * Patterns that indicate high-relevance events regardless of info level
     */
    const RELEVANCE_PATTERNS = [
        'Storage Pool', 'RAID', 'degraded', 'crashed', 'booted up', 'improper shutdown',
        'power supply', 'Bad Sector', 'UNC', 'ioerr', 'Disk', 'removed', 'inserted'
    ];

    public function __construct(string $extractPath)
    {
        $this->extractPath = rtrim($extractPath, DIRECTORY_SEPARATOR);
        $this->locateDatabases();
        $this->detectRelativeCutoffs();
    }

    private function locateDatabases(): void
    {
        // 1. Try "Fast Path" first (Standard Synology locations)
        $fastPaths = [
            $this->extractPath . DIRECTORY_SEPARATOR . 'dsm' . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'synolog',
            $this->extractPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'synolog'
        ];

        foreach ($fastPaths as $path) {
            if (is_dir($path)) {
                foreach (self::TARGET_DBS as $db) {
                    if (file_exists($path . DIRECTORY_SEPARATOR . $db)) {
                        $this->forensicRoot = $path;
                        break 2;
                    }
                }
            }
        }

        // 2. If fast-path failed, perform a depth-limited recursive search
        if (!$this->forensicRoot) {
            $this->forensicRoot = $this->findForensicRoot($this->extractPath);
        }
        
        if (!$this->forensicRoot) {
            return; // No databases found
        }

        foreach (self::TARGET_DBS as $dbName) {
            $path = $this->forensicRoot . DIRECTORY_SEPARATOR . $dbName;
            if (file_exists($path)) {
                $this->dbPaths[$dbName] = $path;
            }
        }
    }

    private function findForensicRoot(string $dir, int $depth = 0): ?string
    {
        if (!is_dir($dir) || $depth > 5) return null;

        // Folders to skip (system/temp noise)
        $blacklist = ['bin', 'usr', 'etc', 'dev', 'lib', 'lib64', 'proc', 'run', 'sys', 'tmp', 'boot'];
        
        $baseName = basename($dir);
        if (in_array(strtolower($baseName), $blacklist)) return null;

        // Check if ANY of the target databases are in this directory
        foreach (self::TARGET_DBS as $db) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $db)) {
                return $dir;
            }
        }

        // Search subdirectories
        $files = @scandir($dir);
        if ($files === false) return null;
        
        $files = array_diff($files, ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $found = $this->findForensicRoot($path, $depth + 1);
                if ($found) return $found;
            }
        }

        return null;
    }

    private function detectRelativeCutoffs(): void
    {
        $latestTime = 0;

        // 1. Find max timestamp across available DBs
        if (isset($this->dbPaths['.SYNOSYSDB'])) {
            try {
                $pdo = $this->getPdo($this->dbPaths['.SYNOSYSDB']);
                $latestTime = (int)$pdo->query("SELECT MAX(time) FROM logs")->fetchColumn();
            } catch (Exception $e) {}
        }

        // Fallback to current time if no logs found
        $baseTime = ($latestTime > 0) ? $latestTime : time();
        
        // 2. Set 365-day cutoff relative to detection
        $this->cutoffUnix = $baseTime - (365 * 86400);
        $this->cutoffDate = date('Y-m-d', $this->cutoffUnix);
    }

    public function parseAll(): array
    {
        $results = [];
        $stats = ['root_path' => $this->forensicRoot ?? 'not found'];
        
        if (empty($this->dbPaths)) {
            return ['errors' => ["No forensic databases located in $this->extractPath"], 'stats' => $stats];
        }

        foreach ($this->dbPaths as $name => $path) {
            try {
                switch ($name) {
                    case '.SYNOSYSDB':
                        $res = $this->parseSystemEvents($path);
                        $results['system_events'] = $res['data'];
                        $stats['system_events'] = $res['stats'];
                        break;
                    case '.SYNODISKHEALTHDB':
                        $res = $this->parseDiskHealth($path);
                        $results['disk_health'] = $res['data'];
                        $stats['disk_health'] = $res['stats'];
                        break;
                    case '.SYNOCONNDB':
                        $res = $this->parseConnections($path);
                        $results['connection_logs'] = $res['data'];
                        $stats['connection_logs'] = $res['stats'];
                        break;
                    case '.SYNODISKDB':
                        $res = $this->parseDiskEvents($path);
                        $results['disk_events'] = $res['data'];
                        $stats['disk_events'] = $res['stats'];
                        break;
                }
            } catch (Exception $e) {
                $results['errors'][] = "Failed to parse $name: " . $e->getMessage();
            }
        }
        
        $results['stats'] = $stats;
        return $results;
    }

    private function isRelevant(string $msg, string $level): bool
    {
        if ($level !== 'info') return true; // Keep all warning/err
        foreach (self::RELEVANCE_PATTERNS as $pattern) {
            if (stripos($msg, $pattern) !== false) return true;
        }
        return false;
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
        
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        
        // Get Rows in window
        $stmt = $pdo->prepare("SELECT time, level, username, msg FROM logs WHERE time >= :cutoff ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $allInWindow = $stmt->fetchAll();

        // 2. Filter by relevance
        $selected = [];
        foreach ($allInWindow as $row) {
            if ($this->isRelevant($row['msg'], $row['level'])) {
                $selected[] = $row;
            }
        }
        
        return [
            'data' => [
                'summary' => ['total' => $total, 'selected' => count($selected)],
                'rows' => $selected
            ],
            'stats' => ['found' => $total, 'selected' => count($selected), 'cutoff_ts' => $this->cutoffUnix]
        ];
    }

    private function parseDiskHealth(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        $errors = $pdo->query("SELECT * FROM disk_error ORDER BY BadSector DESC, UNC DESC")->fetchAll();
        $predictions = $pdo->prepare("SELECT date, serial, model, slot, fail, ui_score, score, threshold, factors, msg FROM prediction WHERE date >= :cutoff ORDER BY date DESC");
        $predictions->execute(['cutoff' => $this->cutoffDate]);
        $predRows = $predictions->fetchAll();
        
        return [
            'data' => ['disk_error' => $errors, 'prediction' => $predRows],
            'stats' => ['disk_rows' => count($errors), 'predictions' => count($predRows)]
        ];
    }

    private function parseConnections(string $path): array
    {
        $pdo = $this->getPdo($path);
        
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        
        // Tiered filter: Warnings/Errors + aggregations
        $stmt = $pdo->prepare("SELECT time, level, username, ip, protocol, msg FROM logs WHERE time >= :cutoff AND level IN ('warning', 'err') ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $critical = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT ip, username, COUNT(*) as attempts, MIN(time) as first_seen, MAX(time) as last_seen FROM logs WHERE time >= :cutoff AND level = 'warning' AND msg LIKE '%failed%' GROUP BY ip, username HAVING attempts >= 3 ORDER BY attempts DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $bruteForce = $stmt->fetchAll();
        
        return [
            'data' => [
                'summary_groups' => [], // Add if needed, simplifying for now
                'brute_force' => $bruteForce,
                'critical_events' => $critical
            ],
            'stats' => ['found' => $total, 'selected_critical' => count($critical)]
        ];
    }

    private function parseDiskEvents(string $path): array
    {
        $pdo = $this->getPdo($path);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();

        $stmt = $pdo->prepare("SELECT serial, model, slot, level, msg, COUNT(*) as cnt FROM logs WHERE time >= :cutoff AND level IN ('warning', 'err') GROUP BY serial, msg ORDER BY cnt DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $summary = $stmt->fetchAll();

        $stmt = $pdo->prepare("SELECT time, level, model, serial, slot, container, msg, errtype, info FROM logs WHERE time >= :cutoff AND level IN ('info', 'warning', 'err') ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $allEvents = $stmt->fetchAll();

        $selected = [];
        foreach ($allEvents as $row) {
            if ($this->isRelevant($row['msg'], $row['level'])) {
                $selected[] = $row;
            }
        }
        
        return [
            'data' => [
                'drive_summary' => $summary,
                'events' => $selected
            ],
            'stats' => ['found' => $total, 'selected' => count($selected)]
        ];
    }
}
