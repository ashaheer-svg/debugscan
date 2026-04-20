<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * NetworkHardwareParser - Extracts physical network hardware health metrics
 *
 * Analyzes ethtool output, network statistics, and connection state
 * to detect link issues, negotiation failures, and error conditions.
 *
 * Sources:
 * - ethtool.ethX.result: Link status, speed, duplex, negotiation
 * - ethtool_info.ethX.result: Hardware capabilities
 * - ethtool_stats.ethX.result: RX/TX errors, CRC, collisions, etc.
 * - netstat.result: Active connections, TIME_WAIT states
 */
class NetworkHardwareParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $network = [
            'interfaces' => [],
            'network_errors' => [],
            'connection_status' => [],
            'health_assessment' => 'unknown',
        ];

        // 1. Extract ethtool interface status
        $interfaces = $this->parseEthtoolInterfaces($extractedPath);
        $network['interfaces'] = $interfaces;

        // 2. Extract network statistics (errors, dropped packets)
        $stats = $this->parseNetworkStatistics($extractedPath, $interfaces);
        $network['network_errors'] = $stats['errors'];

        // 3. Parse netstat for connection analysis
        $connections = $this->parseNetstatConnections($extractedPath);
        $network['connection_status'] = $connections;

        // 4. Detect link flapping (interface going down/up repeatedly)
        $flapping = $this->detectLinkFlapping($extractedPath);
        if ($flapping) {
            $network['link_flapping'] = $flapping;
        }

        // 5. Calculate overall health
        $network['health_assessment'] = $this->assessNetworkHealth($network);

        $citations = [];
        $netstatFile = $extractedPath . '/dsm/result/netstat.result';
        if (file_exists($netstatFile)) {
            $citations[] = [
                'file' => 'dsm/result/netstat.result',
                'lines' => '1-1000',
                'timestamp' => date('Y-m-d H:i:s', filemtime($netstatFile))
            ];
        }

        // Add citation for the messages log if used for flapping detection
        $messagesFile = $extractedPath . '/dsm/var/log/messages';
        if (file_exists($messagesFile)) {
            $citations[] = [
                'file' => 'dsm/var/log/messages',
                'lines' => 'tail-5000',
                'timestamp' => date('Y-m-d H:i:s', filemtime($messagesFile))
            ];
        }

        // Collect ethtool citations
        $resultDir = $extractedPath . '/dsm/result';
        if (is_dir($resultDir)) {
             foreach (scandir($resultDir) as $file) {
                 if (preg_match('/^ethtool\..*\.result$/', $file) || preg_match('/^ethtool_stats\..*\.result$/', $file)) {
                     $citations[] = [
                         'file' => 'dsm/result/' . $file,
                         'lines' => '1',
                         'timestamp' => date('Y-m-d H:i:s', filemtime($resultDir . '/' . $file))
                     ];
                 }
             }
        }

        return [
            'data' => $network,
            'citations' => array_unique($citations, SORT_REGULAR)
        ];
    }

    /**
     * Parse ethtool output for each interface (link status, speed, duplex, negotiation)
     */
    private function parseEthtoolInterfaces(string $path): array
    {
        $interfaces = [];

        // Find all ethtool.ethX.result files
        $resultDir = $path . '/dsm/result';
        if (!is_dir($resultDir)) {
            return $interfaces;
        }

        foreach (scandir($resultDir) as $file) {
            if (preg_match('/^ethtool\.([a-z0-9]+)\.result$/', $file, $matches)) {
                $ifname = $matches[1];
                $content = file_get_contents($resultDir . '/' . $file);

                $interfaces[$ifname] = [
                    'name' => $ifname,
                    'link_detected' => $this->extractValue($content, '/Link detected:\s*(yes|no)/', 1) === 'yes',
                    'speed' => $this->extractValue($content, '/Speed:\s*([0-9]+[^\/\n]+)/', 1),
                    'duplex' => $this->extractValue($content, '/Duplex:\s*([^\n]+)/', 1),
                    'autonegotiation' => $this->extractValue($content, '/Auto-negotiation:\s*([^\n]+)/', 1),
                    'port' => $this->extractValue($content, '/Port:\s*([^\n]+)/', 1),
                    'transceiver' => $this->extractValue($content, '/Transceiver:\s*([^\n]+)/', 1),
                ];

                // Check for negotiation issues
                // Improved regex to capture the full multi-line list of advertised modes
                $advertised = $this->extractValue($content, '/Advertised link modes:\s*(.+?)(?=\n\s*[A-Za-z ]+:\s|$)/s', 1);
                $current = $this->extractValue($content, '/Speed:\s*([0-9]+)/i', 1);

                if ($advertised && $current) {
                    // Optimized to support both Copper (baseT) and Fibre (baseSR/LR/ER/X) standards
                    $interfaces[$ifname]['speed_mismatch'] = !preg_match("/{$current}base[TSRLEX]/i", $advertised);
                }
            }
        }

        return $interfaces;
    }

    /**
     * Extract network statistics (errors, dropped packets, collisions)
     */
    private function parseNetworkStatistics(string $path, array $interfaces): array
    {
        $errors = [];

        $resultDir = $path . '/dsm/result';
        if (!is_dir($resultDir)) {
            return ['errors' => []];
        }

        foreach (array_keys($interfaces) as $ifname) {
            $statsFile = $resultDir . '/ethtool_stats.' . $ifname . '.result';
            if (!file_exists($statsFile)) {
                continue;
            }

            $content = file_get_contents($statsFile);
            $stats = [];

            // Extract critical error counters
            $stats['rx_crc_errors'] = (int)$this->extractValue($content, '/rx_crc_errors:\s*(\d+)/', 1, 0);
            $stats['tx_carrier_errors'] = (int)$this->extractValue($content, '/tx_carrier_errors:\s*(\d+)/', 1, 0);
            $stats['rx_dropped'] = (int)$this->extractValue($content, '/rx_dropped:\s*(\d+)/', 1, 0);
            $stats['tx_dropped'] = (int)$this->extractValue($content, '/tx_dropped:\s*(\d+)/', 1, 0);
            $stats['collisions'] = (int)$this->extractValue($content, '/collisions:\s*(\d+)/', 1, 0);
            $stats['rx_errors'] = (int)$this->extractValue($content, '/rx_errors:\s*(\d+)/', 1, 0);
            $stats['tx_errors'] = (int)$this->extractValue($content, '/tx_errors:\s*(\d+)/', 1, 0);
            $stats['rx_packets'] = (int)$this->extractValue($content, '/rx_packets:\s*(\d+)/', 1, 0);
            $stats['tx_packets'] = (int)$this->extractValue($content, '/tx_packets:\s*(\d+)/', 1, 0);

            // Flag critical conditions
            if ($stats['rx_crc_errors'] > 0 || $stats['tx_carrier_errors'] > 0) {
                $errors[] = [
                    'interface' => $ifname,
                    'severity' => 'critical',
                    'issue' => 'Physical layer errors detected',
                    'details' => "CRC: {$stats['rx_crc_errors']}, Carrier: {$stats['tx_carrier_errors']}",
                    'likely_cause' => 'Cable, connector, or transceiver issue'
                ];
            }

            if ($stats['collisions'] > 0) {
                $errors[] = [
                    'interface' => $ifname,
                    'severity' => 'warning',
                    'issue' => 'Network collisions detected',
                    'details' => "Collisions: {$stats['collisions']}",
                    'likely_cause' => 'Network congestion or half-duplex negotiation'
                ];
            }

            if (($stats['rx_dropped'] + $stats['tx_dropped']) > 100) {
                $errors[] = [
                    'interface' => $ifname,
                    'severity' => 'warning',
                    'issue' => 'Dropped packets detected',
                    'details' => "RX dropped: {$stats['rx_dropped']}, TX dropped: {$stats['tx_dropped']}",
                    'likely_cause' => 'Buffer overflow or kernel resource exhaustion'
                ];
            }

            $interfaces[$ifname]['statistics'] = $stats;
        }

        return ['errors' => $errors];
    }

    /**
     * Parse netstat output for connection anomalies
     */
    private function parseNetstatConnections(string $path): array
    {
        $connections = [
            'established' => 0,
            'time_wait' => 0,
            'listen' => 0,
            'close_wait' => 0,
            'fin_wait' => 0,
            'anomalies' => []
        ];

        $netstatFile = $path . '/dsm/result/netstat.result';
        if (!file_exists($netstatFile)) {
            return $connections;
        }

        $content = file_get_contents($netstatFile);
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            if (preg_match('/\s+(ESTABLISHED|TIME_WAIT|LISTEN|CLOSE_WAIT|FIN_WAIT1|FIN_WAIT2)/', $line, $matches)) {
                $state = $matches[1];
                switch ($state) {
                    case 'ESTABLISHED':
                        $connections['established']++;
                        break;
                    case 'TIME_WAIT':
                        $connections['time_wait']++;
                        break;
                    case 'LISTEN':
                        $connections['listen']++;
                        break;
                    case 'CLOSE_WAIT':
                        $connections['close_wait']++;
                        break;
                    case 'FIN_WAIT1':
                    case 'FIN_WAIT2':
                        $connections['fin_wait']++;
                        break;
                }
            }
        }

        // Flag anomalies
        if ($connections['time_wait'] > 500) {
            $connections['anomalies'][] = [
                'severity' => 'warning',
                'issue' => 'Excessive TIME_WAIT connections',
                'count' => $connections['time_wait'],
                'likely_cause' => 'High connection turnover or connection leak'
            ];
        }

        if ($connections['close_wait'] > 100) {
            $connections['anomalies'][] = [
                'severity' => 'warning',
                'issue' => 'Excessive CLOSE_WAIT connections',
                'count' => $connections['close_wait'],
                'likely_cause' => 'Application not closing connections properly'
            ];
        }

        return $connections;
    }

    /**
     * Detect link flapping by correlating timestamps across multiple debug files
     * This would require timestamp metadata from system logs
     */
    private function detectLinkFlapping(string $path): ?array
    {
        // Look for link state change messages in kernel logs
        $messagesFile = $path . '/dsm/var/log/messages';
        if (!file_exists($messagesFile)) {
            return null;
        }

        $content = file_get_contents($messagesFile);
        $linkEvents = [];

        // Match kernel messages about link state changes
        if (preg_match_all('/\[([^\]]+)\].*\[(eth[0-9]+)\].*\blower carrier state\b/', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $linkEvents[] = [
                    'timestamp' => $match[1],
                    'interface' => $match[2],
                ];
            }
        }

        if (count($linkEvents) > 3) {
            return [
                'detected' => true,
                'event_count' => count($linkEvents),
                'events' => $linkEvents,
                'severity' => count($linkEvents) > 10 ? 'critical' : 'warning',
                'recommendation' => 'Check cable connections, transceiver health, and network switch port status'
            ];
        }

        return null;
    }

    /**
     * Calculate overall network health based on interface status and errors
     */
    private function assessNetworkHealth(array $network): string
    {
        // Count critical issues
        $criticalCount = 0;
        $warningCount = 0;

        $totalEth = 0;
        $downEth = 0;

        foreach ($network['interfaces'] as $ifname => $if) {
            // Only aggregate physical ethernet ports (eth0, eth1, etc.)
            if (preg_match('/^eth/i', (string)$ifname)) {
                $totalEth++;
                if (!$if['link_detected']) {
                    $downEth++;
                }
            }
            
            if ($if['speed_mismatch'] ?? false) {
                $warningCount++;
            }
        }

        // Flag as critical ONLY if all physical ports are down
        if ($totalEth > 0 && $downEth === $totalEth) {
            $criticalCount++;
        }

        foreach ($network['network_errors'] as $error) {
            if ($error['severity'] === 'critical') {
                $criticalCount++;
            } elseif ($error['severity'] === 'warning') {
                $warningCount++;
            }
        }

        if ($network['link_flapping'] ?? false) {
            if ($network['link_flapping']['severity'] === 'critical') {
                $criticalCount += 2;
            } else {
                $warningCount++;
            }
        }

        // Determine health grade
        if ($criticalCount > 0) {
            return 'critical';
        } elseif ($warningCount > 2) {
            return 'warning';
        } elseif ($warningCount > 0) {
            return 'caution';
        } else {
            return 'healthy';
        }
    }

    /**
     * Helper: Extract value from content using regex
     */
    private function extractValue(string $content, string $pattern, int $group = 1, mixed $default = null): mixed
    {
        if (preg_match($pattern, $content, $matches)) {
            return trim($matches[$group]);
        }
        return $default;
    }
}
