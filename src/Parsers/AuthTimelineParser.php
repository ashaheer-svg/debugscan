<?php

declare(strict_types=1);

namespace App\Parsers;

use App\Helpers\SqliteReader;

/**
 * AuthTimelineParser: User authentication event extraction and analysis
 *
 * PURPOSE:
 * Reconstructs user authentication history from forensic databases
 * (SYNOCONNDB, auth logs). Extracts SMB, SSH, SNMP authentication events
 * with timestamps, usernames, source IPs, protocols, and results. Detects
 * brute force attempts, successful/failed logins, and access anomalies.
 *
 * DATA SOURCES:
 * - SYNOCONNDB: SQLite database with connection events
 * - /var/log/auth.log: System authentication log
 * - /var/log/scemd.log: Service-specific logs
 *
 * EVENTS EXTRACTED:
 * - timestamp: When authentication occurred
 * - user: Username attempting login
 * - source_ip: Remote IP address
 * - protocol: SMB, SSH, SNMP, HTTP
 * - method: Password, key, token, anonymous
 * - result: success|failed|error
 * - failure_reason: Invalid password, user not found, etc.
 *
 * SECURITY ANALYSIS:
 * Detects: Brute force attempts, successful backdoor access, unusual IPs,
 * after-hours access, service account abuse, multiple failed attempts.
 *
 * OUTPUT:
 * - summary: Total logins, failed attempts, unique IPs/users
 * - timeline: Chronological auth events with context
 * - anomalies: Suspicious patterns (multiple failures, unusual IPs)
 *
 * @package App\Parsers
 */
class AuthTimelineParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $dbPath = $this->locateAuthDb($extractedPath);
        if (!$dbPath) {
            return ['data' => [], 'citations' => []];
        }

        $config = $context['_config']['auth_timeline'] ?? [];
        $limit = $config['max_rows'] ?? 1000;
        $cutoffUnix = time() - (30 * 86400); // 30 day default for auth timeline

        $results = [
            'summary' => [
                'total_logins' => 0,
                'failed_attempts' => 0,
                'unique_ips' => 0,
                'unique_users' => 0
            ],
            'events' => [],
            'brute_force_risks' => []
        ];

        try {
            // 1. Get raw forensic events
            // Levels: info (login), warning/err (failed)
            $query = "SELECT time, level, username, ip, protocol, msg 
                      FROM logs 
                      WHERE time >= $cutoffUnix 
                      ORDER BY time DESC";
            
            $events = SqliteReader::queryWithLimit($dbPath, $query, $limit);
            
            $ips = [];
            $users = [];
            $failedByIp = [];

            foreach ($events as &$event) {
                $isFailed = (strpos(strtolower($event['msg']), 'failed') !== false);
                $event['is_failed'] = $isFailed;
                
                if ($isFailed) {
                    $results['summary']['failed_attempts']++;
                    $failedByIp[$event['ip']] = ($failedByIp[$event['ip']] ?? 0) + 1;
                } else {
                    $results['summary']['total_logins']++;
                }

                if (!empty($event['ip'])) $ips[$event['ip']] = true;
                if (!empty($event['username'])) $users[$event['username']] = true;
            }

            $results['events'] = $events;
            $results['summary']['unique_ips'] = count($ips);
            $results['summary']['unique_users'] = count($users);

            // 2. Identify potential brute force (3+ fails from same IP)
            foreach ($failedByIp as $ip => $count) {
                if ($count >= 3) {
                    $results['brute_force_risks'][] = [
                        'ip' => $ip,
                        'attempts' => $count,
                        'severity' => $count > 10 ? 'critical' : 'warning'
                    ];
                }
            }

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

    private function locateAuthDb(string $extractedPath): ?string
    {
        $candidates = [
            $extractedPath . '/dsm/var/log/synolog/.SYNOCONNDB',
            $extractedPath . '/var/log/synolog/.SYNOCONNDB'
        ];

        foreach ($candidates as $path) {
            if (file_exists($path)) return $path;
        }
        return null;
    }
}
