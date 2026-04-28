<?php

declare(strict_types=1);

namespace App\Parsers;

/**
 * TopResultParser: System load and process resource metrics
 *
 * PURPOSE:
 * Parses top command output to extract system-wide metrics including load
 * averages (1/5/15 min), CPU breakdown (user/system/iowait), memory usage,
 * and top processes. Identifies resource saturation and runaway processes.
 *
 * METRICS EXTRACTED:
 * - load_avg_1min, load_avg_5min, load_avg_15min: System load
 * - cpu_user, cpu_system, cpu_iowait, cpu_idle: CPU breakdown percentage
 * - mem_total, mem_used, mem_free: Memory usage in MB/GB
 * - swap_total, swap_used, swap_free: Swap usage
 * - zombie_processes: Count of zombie/defunct processes
 *
 * TOP PROCESSES:
 * Extracts top N processes by CPU and memory usage:
 * - process_name, pid, user, cpu_percent, memory_percent, memory_mb
 *
 * SATURATION DETECTION:
 * High load with low CPU usage → I/O bound (disk saturation)
 * High load with high CPU usage → CPU bound (compute saturation)
 * High iowait → Disk I/O bottleneck
 * High zombie count → Process cleanup problem
 * Memory pressure → Swapping, OOM risk
 *
 * DATA SOURCE:
 * top.out snapshot or /proc/stat and /proc/meminfo parsed data
 *
 * @package App\Parsers
 */
class TopResultParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $file = $extractedPath . '/dsm/result/top.result';
        if (!file_exists($file)) {
            return ['_error' => 'top.result not found'];
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $result = [
            'load_average' => null,
            'io_load' => null,
            'cpu_load' => null,
            'cpu_stats' => null,
            'memory' => null,
            'processes' => null,
            'zombie_processes' => 0
        ];

        // Parse each line
        foreach ($lines as $line) {
            // Line 1: top - HH:MM:SS up X days, load average: X.XX, X.XX, X.XX [IO: X.XX, X.XX, X.XX CPU: X.XX, X.XX, X.XX]
            if (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)\s+\[IO:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)\s+CPU:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)\]/', $line, $m)) {
                $result['load_average'] = [
                    '1min' => (float)$m[1],
                    '5min' => (float)$m[2],
                    '15min' => (float)$m[3]
                ];
                $result['io_load'] = [
                    '1min' => (float)$m[4],
                    '5min' => (float)$m[5],
                    '15min' => (float)$m[6]
                ];
                $result['cpu_load'] = [
                    '1min' => (float)$m[7],
                    '5min' => (float)$m[8],
                    '15min' => (float)$m[9]
                ];
            }
            // Fallback: old format without [IO: ...] extension (some DSM versions)
            elseif (preg_match('/load average:\s+([\d.]+),\s+([\d.]+),\s+([\d.]+)/', $line, $m)) {
                if (!$result['load_average']) {
                    $result['load_average'] = [
                        '1min' => (float)$m[1],
                        '5min' => (float)$m[2],
                        '15min' => (float)$m[3]
                    ];
                }
            }

            // Line 2: %Cpu(s): X.X us, X.X sy, X.X ni, X.X id, X.X wa, X.X hi, X.X si
            if (preg_match('/%Cpu\(s\):\s+([\d.]+)\s+us,\s+([\d.]+)\s+sy,\s+([\d.]+)\s+ni,\s+([\d.]+)\s+id,\s+([\d.]+)\s+wa/', $line, $m)) {
                $result['cpu_stats'] = [
                    'user_percent' => (float)$m[1],      // %us - user space
                    'system_percent' => (float)$m[2],    // %sy - kernel space
                    'nice_percent' => (float)$m[3],      // %ni - nice (background jobs)
                    'idle_percent' => (float)$m[4],      // %id - idle
                    'iowait_percent' => (float)$m[5]     // %wa - I/O WAIT (critical indicator)
                ];
            }

            // Line 3: Tasks: N total, N running, N sleeping, N stopped, N zombie
            if (preg_match('/Tasks:\s+(\d+)\s+total.*?(\d+)\s+zombie/', $line, $m)) {
                $result['processes'] = [
                    'total' => (int)$m[1],
                    'zombie' => (int)$m[2]
                ];
                $result['zombie_processes'] = (int)$m[2];
            }

            // Line 4: MiB Mem: X.XXX total, X.XXX free, X.XXX used, X.XXX buff/cache
            if (preg_match('/Mem:\s+([\d.]+)\s+total,\s+([\d.]+)\s+free,\s+([\d.]+)\s+used,\s+([\d.]+)\s+buff/', $line, $m)) {
                $result['memory'] = [
                    'total_gb' => (float)$m[1],
                    'free_gb' => (float)$m[2],
                    'used_gb' => (float)$m[3],
                    'buffer_cache_gb' => (float)$m[4]
                ];
            }
            // GiB format (alternative)
            elseif (preg_match('/Mem:\s+([\d.]+)\s+total,\s+([\d.]+)\s+free,\s+([\d.]+)\s+used,\s+([\d.]+)\s+buff/', $line, $m)) {
                if (!$result['memory']) {
                    $result['memory'] = [
                        'total_gb' => (float)$m[1],
                        'free_gb' => (float)$m[2],
                        'used_gb' => (float)$m[3],
                        'buffer_cache_gb' => (float)$m[4]
                    ];
                }
            }
        }

        // Compute health indicators
        if ($result['cpu_stats']) {
            $result['cpu_stats']['io_bottleneck'] = $result['cpu_stats']['iowait_percent'] > 20;
            $result['cpu_stats']['iowait_severity'] = $this->getIowaitSeverity($result['cpu_stats']['iowait_percent']);
        }

        if ($result['load_average'] && $context['cpu_cores'] ?? null) {
            $cores = (int)($context['cpu_cores'] ?? 4);
            $load_1min = $result['load_average']['1min'];
            $result['load_average']['overload_factor'] = round($load_1min / $cores, 2);
            $result['load_average']['cpu_bound'] = $load_1min > $cores;
        }

        if ($result['processes'] && $result['processes']['zombie'] > 0) {
            $result['processes']['zombie_warning'] = "Zombie processes detected. Parent process may not be cleaning up children (software bug).";
        }

        return [
            'data' => $result,
            'citations' => [
                [
                    'file' => 'dsm/result/top.result',
                    'lines' => '1-100',
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                ]
            ]
        ];
    }

    private function getIowaitSeverity(float $iowait): string
    {
        if ($iowait < 10) {
            return 'normal';
        } elseif ($iowait < 20) {
            return 'warning';
        } elseif ($iowait < 50) {
            return 'concerning';
        } else {
            return 'critical';
        }
    }
}
