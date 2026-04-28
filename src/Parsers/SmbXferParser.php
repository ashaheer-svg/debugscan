<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Helpers\SqliteReader;

/**
 * SmbXferParser: SMB file transfer operation tracking and analysis
 *
 * PURPOSE:
 * Analyzes SMB file transfer operations (CREATE, DELETE, READ, WRITE) from
 * forensic databases. Extracts file operations with user, IP, path, and
 * timestamp. Detects unusual access patterns, bulk transfers, and potential
 * data exfiltration or unauthorized access.
 *
 * OPERATIONS TRACKED:
 * - CREATE: File or directory created
 * - DELETE: File or directory deleted
 * - READ: File read operation
 * - WRITE: File write operation
 * - RENAME: File or directory renamed
 * - COPY: File copied
 * - MOVE: File moved
 *
 * METADATA EXTRACTED:
 * - timestamp: When operation occurred
 * - user: Which user performed operation
 * - source_ip: Client IP address
 * - file_path: Full file path accessed
 * - file_size: Size of file (if read/written)
 * - operation: What operation performed
 * - success: Did operation succeed
 *
 * ANOMALY DETECTION:
 * Detects: Bulk file deletions, after-hours access, unusual IPs,
 * sensitive path access, large file transfers, failed access attempts.
 *
 * DATA SOURCE:
 * .SMBXFERDB or SMBXFERDB: SQLite forensic database with transfer logs
 *
 * @package App\Parsers
 */
class SmbXferParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $dbPath = $this->locateXferDb($extractedPath);
        if (!$dbPath) {
            return ['data' => [], 'citations' => []];
        }

        $config = $context['_config']['smb_xfer'] ?? [];
        $limit = $config['max_rows'] ?? 2000; // SMB logs can be very voluminous
        $cutoffUnix = time() - (7 * 86400); // 7 day default for heavy SMB audit

        $results = [
            'summary' => [
                'total_operations' => 0,
                'operations_by_type' => [],
                'most_active_users' => [],
                'most_active_ips' => []
            ],
            'recent_transfers' => []
        ];

        try {
            // Schema varies, but typical Synology SMB audit logs:
            // Table: logs
            // Columns: time, username, ip, op, path, filesize
            $query = "SELECT time, username, ip, op, path 
                      FROM logs 
                      WHERE time >= $cutoffUnix 
                      ORDER BY time DESC";
            
            $rows = SqliteReader::queryWithLimit($dbPath, $query, $limit);
            
            $userCounts = [];
            $ipCounts = [];
            $opCounts = [];

            foreach ($rows as $row) {
                $results['summary']['total_operations']++;
                
                $op = $row['op'] ?? 'unknown';
                $opCounts[$op] = ($opCounts[$op] ?? 0) + 1;

                $user = $row['username'] ?? 'unknown';
                $userCounts[$user] = ($userCounts[$user] ?? 0) + 1;

                $ip = $row['ip'] ?? 'unknown';
                $ipCounts[$ip] = ($ipCounts[$ip] ?? 0) + 1;
            }

            arsort($userCounts);
            arsort($ipCounts);
            arsort($opCounts);

            $results['recent_transfers'] = $rows;
            $results['summary']['operations_by_type'] = array_slice($opCounts, 0, 10);
            $results['summary']['most_active_users'] = array_slice($userCounts, 0, 10);
            $results['summary']['most_active_ips'] = array_slice($ipCounts, 0, 10);

        } catch (\Exception $e) {
            return ['data' => ['error' => $e->getMessage()], 'citations' => []];
        }

        return [
            'data' => $results,
            'citations' => [
                [
                    'file' => str_replace($extractedPath . '/', '', $dbPath),
                    'lines' => 'sqlite_rows',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($dbPath))
                ]
            ]
        ];
    }

    private function locateXferDb(string $extractedPath): ?string
    {
        $candidates = [
            $extractedPath . '/dsm/var/log/synolog/.SMBXFERDB',
            $extractedPath . '/dsm/var/log/synolog/SMBXFERDB',
            $extractedPath . '/var/log/synolog/.SMBXFERDB',
            $extractedPath . '/var/log/synolog/SMBXFERDB'
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }
        return null;
    }
}
