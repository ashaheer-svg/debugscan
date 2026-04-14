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

    private ?string $relevanceRegex = null;
    private $onProgress = null;
    private array $config = [];

    public function __construct(string $extractPath, ?callable $onProgress = null, array $config = [])
    {
        $this->extractPath = rtrim($extractPath, DIRECTORY_SEPARATOR);
        $this->onProgress = $onProgress;
        $this->config = $config;
        
        $this->locateDatabases();
        $this->detectRelativeCutoffs();
        
        // Build optimized regex for pattern matching
        $patterns = array_map('preg_quote', self::RELEVANCE_PATTERNS);
        $this->relevanceRegex = '/' . implode('|', $patterns) . '/i';
    }

    private function locateDatabases(): void
    {
        // 1. Expanded "Fast Path" list (Standard Synology locations)
        $fastPaths = [
            'dsm/var/log/synolog',
            'var/log/synolog',
            'synolog',
            'var/log',
            'dsm/var/log',
            'logs/synolog'
        ];

        foreach ($fastPaths as $relPath) {
            $path = $this->extractPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
            if ($this->onProgress) ($this->onProgress)("Discovery: Checking $relPath");
            
            if (is_dir($path)) {
                foreach (self::TARGET_DBS as $db) {
                    if (file_exists($path . DIRECTORY_SEPARATOR . $db)) {
                        if ($this->onProgress) ($this->onProgress)("Discovery: Found Databases in $relPath");
                        $this->forensicRoot = $path;
                        break 2;
                    }
                }
            }
        }

        // 2. If fast-path failed, perform a very depth-limited recursive search
        if (!$this->forensicRoot) {
            if ($this->onProgress) ($this->onProgress)("Discovery: Deep-Scan started...");
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
        // Limit depth even further to avoid hangups on massive extractions
        if (!is_dir($dir) || $depth > 3) return null;

        // Folders to skip (system/temp noise)
        $blacklist = ['bin', 'usr', 'etc', 'dev', 'lib', 'lib64', 'proc', 'run', 'sys', 'tmp', 'boot', 'target', 'node_modules'];
        
        $baseName = basename($dir);
        if (in_array(strtolower($baseName), $blacklist)) return null;

        if ($this->onProgress && $depth > 0) {
            ($this->onProgress)("Discovery: [FORENSIC] Locating Databases in " . basename($dir));
        }

        // Check if ANY of the target databases are in this directory
        foreach (self::TARGET_DBS as $db) {
            if (file_exists($dir . DIRECTORY_SEPARATOR . $db)) {
                return $dir;
            }
        }

        // Search subdirectories
        $files = @scandir($dir);
        if ($files === false) return null;
        
        $files = array_diff($files, ['.', '..', '.git', '.svn']);
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
                        if ($this->isSectionEnabled('db_system_events')) {
                            $limit = $this->getSectionLimit('db_system_events');
                            $res = $this->parseSystemEvents($path, $this->onProgress, $limit);
                            $results['system_events'] = $res['data'];
                            $stats['system_events'] = $res['stats'];
                        }
                        break;
                    case '.SYNODISKHEALTHDB':
                        if ($this->isSectionEnabled('db_disk_health')) {
                            $res = $this->parseDiskHealth($path);
                            $results['disk_health'] = $res['data'];
                            $stats['disk_health'] = $res['stats'];
                        }
                        break;
                    case '.SYNOCONNDB':
                        if ($this->isSectionEnabled('db_connection_logs')) {
                            $limit = $this->getSectionLimit('db_connection_logs');
                            $res = $this->parseConnections($path, $this->onProgress, $limit);
                            $results['connection_logs'] = $res['data'];
                            $stats['connection_logs'] = $res['stats'];
                        }
                        break;
                    case '.SYNODISKDB':
                        if ($this->isSectionEnabled('db_disk_events')) {
                            $limit = $this->getSectionLimit('db_disk_events');
                            $res = $this->parseDiskEvents($path, $this->onProgress, $limit);
                            $results['disk_events'] = $res['data'];
                            $stats['disk_events'] = $res['stats'];
                        }
                        break;
                }
            } catch (Exception $e) {
                $results['errors'][] = "Failed to parse $name: " . $e->getMessage();
            }
        }
        
        $results['stats'] = $stats;
        return $results;
    }

    private function isSectionEnabled(string $key): bool
    {
        return $this->config[$key]['is_enabled'] ?? true;
    }

    private function getSectionLimit(string $key): ?int
    {
        $v = $this->config[$key]['max_rows'] ?? null;
        return $v !== null ? (int)$v : null;
    }

    private function isRelevant(string $msg, string $level): bool
    {
        if ($level !== 'info') return true; // Keep all warning/err
        return preg_match($this->relevanceRegex, $msg) === 1;
    }

    private function getPdo(string $path): PDO
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new Exception("Missing 'pdo_sqlite' PHP extension on this server. Deep forensic extraction disabled.");
        }
        $pdo = new PDO("sqlite:" . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        return $pdo;
    }

    private function parseSystemEvents(string $path, ?callable $onProgress = null, ?int $limit = null): array
    {
        $pdo = $this->getPdo($path);
        
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        
        // Memory-safe stream processing
        $stmt = $pdo->prepare("SELECT time, level, username, msg FROM logs WHERE time >= :cutoff ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        
        $selected = [];
        $scanned = 0;
        
        while ($row = $stmt->fetch()) {
            $scanned++;
            if ($onProgress && $scanned % 5000 === 0) {
                $onProgress("Technical Audit: Extracting System Events (" . number_format($scanned) . " / " . number_format($total) . " total lines)");
            }

            if ($this->isRelevant($row['msg'], $row['level'])) {
                $selected[] = $row;
            }
        }

        // Apply admin-configured row limit
        if ($limit !== null && count($selected) > $limit) {
            $selected = array_slice($selected, -$limit);
        }
        
        return [
            'data'  => ['summary' => ['total' => $total, 'selected' => count($selected)], 'rows' => $selected],
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

    private function parseConnections(string $path, ?callable $onProgress = null, ?int $limit = null): array
    {
        $pdo = $this->getPdo($path);
        
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();
        
        // Tiered filter: Warnings/Errors + aggregations
        $stmt = $pdo->prepare("SELECT time, level, username, ip, protocol, msg FROM logs WHERE time >= :cutoff AND (level IN ('warning', 'err') OR msg LIKE '%failed%') ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);

        $critical = [];
        $scanned = 0;
        while ($row = $stmt->fetch()) {
            $scanned++;
            if ($onProgress && $scanned % 5000 === 0) {
                $onProgress("Technical Audit: Analyzing Connection Logs (" . number_format($scanned) . " lines processed)");
            }
            $critical[] = $row;
        }

        if ($limit !== null && count($critical) > $limit) {
            $critical = array_slice($critical, -$limit);
        }

        $stmt = $pdo->prepare("SELECT ip, username, COUNT(*) as attempts, MIN(time) as first_seen, MAX(time) as last_seen FROM logs WHERE time >= :cutoff AND level = 'warning' AND msg LIKE '%failed%' GROUP BY ip, username HAVING attempts >= 3 ORDER BY attempts DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $bruteForce = $stmt->fetchAll();
        
        return [
            'data'  => ['summary_groups' => [], 'brute_force' => $bruteForce, 'critical_events' => $critical],
            'stats' => ['found' => $total, 'selected_critical' => count($critical)]
        ];
    }

    private function parseDiskEvents(string $path, ?callable $onProgress = null, ?int $limit = null): array
    {
        $pdo = $this->getPdo($path);
        $total = (int)$pdo->query("SELECT COUNT(*) FROM logs")->fetchColumn();

        $stmt = $pdo->prepare("SELECT serial, model, slot, level, msg, COUNT(*) as cnt FROM logs WHERE time >= :cutoff AND level IN ('warning', 'err') GROUP BY serial, msg ORDER BY cnt DESC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);
        $summary = $stmt->fetchAll();

        // Memory-safe stream for detailed events
        $stmt = $pdo->prepare("SELECT time, level, model, serial, slot, container, msg, errtype, info FROM logs WHERE time >= :cutoff ORDER BY time ASC");
        $stmt->execute(['cutoff' => $this->cutoffUnix]);

        $selected = [];
        $scanned = 0;
        while ($row = $stmt->fetch()) {
            $scanned++;
            if ($onProgress && $scanned % 5000 === 0) {
                $onProgress("Technical Audit: Extracting Disk Events (" . number_format($scanned) . " / " . number_format($total) . " lines)");
            }

            if ($this->isRelevant($row['msg'] ?? '', $row['level'] ?? '')) {
                $selected[] = $row;
            }
        }

        if ($limit !== null && count($selected) > $limit) {
            $selected = array_slice($selected, -$limit);
        }
        
        return [
            'data'  => ['drive_summary' => $summary, 'events' => $selected],
            'stats' => ['found' => $total, 'selected' => count($selected)]
        ];
    }
}
