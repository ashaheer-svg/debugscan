<?php

declare(strict_types=1);

namespace App\Parsers;

class VmstatParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $result = [
            'meminfo' => [],
            'vmstat' => [],
            'memory_pressure' => null,
            'swap_usage' => null,
            'oom_risk' => false
        ];

        // 1. Parse /proc/meminfo
        $meminfoFile = $extractedPath . '/dsm/proc/meminfo';
        if (file_exists($meminfoFile)) {
            $result['meminfo'] = $this->parseMeminfo($meminfoFile);
        }

        // 2. Parse /proc/vmstat
        $vmstatFile = $extractedPath . '/dsm/proc/vmstat';
        if (file_exists($vmstatFile)) {
            $result['vmstat'] = $this->parseVmstat($vmstatFile);
        }

        // 3. Compute memory pressure indicators
        if (!empty($result['meminfo']) && !empty($result['vmstat'])) {
            $result['memory_pressure'] = $this->computeMemoryPressure($result['meminfo'], $result['vmstat']);
            $result['swap_usage'] = $this->analyzeSwapUsage($result['meminfo']);
            $result['oom_risk'] = $this->assessOomRisk($result['meminfo'], $result['vmstat']);
        }

        $citations = [];
        if (file_exists($meminfoFile)) {
            $citations[] = [
                'file' => 'dsm/proc/meminfo',
                'lines' => '1',
                'timestamp' => date('Y-m-d H:i:s', filemtime($meminfoFile))
            ];
        }
        if (file_exists($vmstatFile)) {
            $citations[] = [
                'file' => 'dsm/proc/vmstat',
                'lines' => '1',
                'timestamp' => date('Y-m-d H:i:s', filemtime($vmstatFile))
            ];
        }

        return ['data' => $result, 'citations' => $citations];
    }

    private function parseMeminfo(string $file): array
    {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $data = [];

        foreach ($lines as $line) {
            if (preg_match('/^([^:]+):\s+(\d+)/', $line, $m)) {
                $key = trim($m[1]);
                $value_kb = (int)$m[2];
                $value_gb = round($value_kb / 1024 / 1024, 2);

                $data[str_replace(' ', '_', strtolower($key))] = [
                    'kb' => $value_kb,
                    'gb' => $value_gb
                ];
            }
        }

        return $data;
    }

    private function parseVmstat(string $file): array
    {
        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $data = [];

        foreach ($lines as $line) {
            if (preg_match('/^([^ ]+)\s+(\d+)/', $line, $m)) {
                $key = trim($m[1]);
                $value = (int)$m[2];
                $data[$key] = $value;
            }
        }

        return $data;
    }

    private function computeMemoryPressure(array $meminfo, array $vmstat): string
    {
        // Key indicators of memory crisis
        $pgscan_direct = $vmstat['pgscan_direct'] ?? 0;
        $pgsteal_direct = $vmstat['pgsteal_direct'] ?? 0;
        $nr_free_pages = $vmstat['nr_free_pages'] ?? 0;
        $nr_dirty = $vmstat['nr_dirty'] ?? 0;

        // Thresholds for crisis detection
        $page_size_kb = 4; // Linux standard
        $free_memory_mb = ($nr_free_pages * $page_size_kb) / 1024;

        // CRITICAL: pgscan_direct > 0 means active memory reclaim happening NOW
        if ($pgscan_direct > 0 || $pgsteal_direct > 0) {
            return 'critical';  // System is actively struggling with memory
        }

        // WARNING: Very low free pages
        if ($nr_free_pages < 1000) {  // < 4 MB free
            return 'warning';
        }

        // WARNING: Excessive dirty pages (unflushed writes)
        if ($nr_dirty > 500000) {  // > 2 GB dirty
            return 'warning';
        }

        // Normal
        return 'normal';
    }

    private function analyzeSwapUsage(array $meminfo): array
    {
        $swap_total = $meminfo['swaptotal']['kb'] ?? 0;
        $swap_free = $meminfo['swapfree']['kb'] ?? 0;
        $swap_used = $swap_total - $swap_free;

        // Synology uses ZRAM (compressed RAM) and disk-backed swap
        // ZRAM usage is normal and does not indicate memory pressure
        // Disk-backed swap usage (> 0) is more concerning

        return [
            'total_gb' => round($swap_total / 1024 / 1024, 2),
            'free_gb' => round($swap_free / 1024 / 1024, 2),
            'used_gb' => round($swap_used / 1024 / 1024, 2),
            'percent_used' => $swap_total > 0 ? round(($swap_used / $swap_total) * 100, 1) : 0,
            'swap_active' => $swap_used > 0,
            'type_note' => 'May be ZRAM (normal) or disk-backed (concerning). Cross-reference with vmstat.'
        ];
    }

    private function assessOomRisk(array $meminfo, array $vmstat): bool
    {
        // OOM (Out of Memory) risk assessment
        $mem_available = $meminfo['memavailable']['kb'] ?? $meminfo['memfree']['kb'] ?? 0;
        $mem_total = $meminfo['memtotal']['kb'] ?? 1;

        $pgscan_direct = $vmstat['pgscan_direct'] ?? 0;
        $page_reclaim_attempts = $vmstat['pgsteal_direct'] ?? 0;

        // Risk factors:
        // 1. Active direct page reclaim (pgscan_direct > 0) = system in memory crisis
        // 2. Very low available memory (< 5% of total)
        // 3. Growing page fault rate

        $available_percent = ($mem_available / $mem_total) * 100;

        return $pgscan_direct > 0 || $available_percent < 5;
    }
}
