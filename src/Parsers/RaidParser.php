<?php

declare(strict_types=1);

namespace App\Parsers;

class RaidParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        $file = $extractedPath . '/dsm/proc/mdstat';
        if (!file_exists($file)) return [];

        $content = file_get_contents($file);
        $lines = explode("\n", $content);
        $raids = [];
        $currentMd = null;

        foreach ($lines as $line) {
            // Match mdX : active raidY disk1[0] disk2[1]
            if (preg_match('/^(md\d+) : active (raid\d+|linear|multipath) (.*)/', $line, $matches)) {
                $currentMd = $matches[1];
                $raids[$currentMd] = [
                    'level' => $matches[2],
                    'disks' => $this->parseDisks($matches[3]),
                    'status' => 'active',
                    'type' => $this->classifyArray($currentMd),  // Internal vs User array
                ];
            } elseif ($currentMd && preg_match('/^\s+(\d+) blocks super (.*) \[(\d+)\/(\d+)\] \[(.*)\]/', $line, $matches)) {
                $raids[$currentMd]['size_gb'] = round((int)$matches[1] / 1024 / 1024, 2);
                $raids[$currentMd]['sync_status'] = $matches[5];

                // FIXED: [N/M] notation: N = configured drives, M = active/online drives
                // Hardwarev2.md Section 2.3.1 & 3.3.1
                $configured = (int)$matches[3];
                $active = (int)$matches[4];

                // NOTE: health determination is complex:
                // - If configured == active: array is healthy (all expected drives online)
                // - If configured > active: could mean (a) empty bays (expected), or (b) failed drives (degradation)
                //   Cannot distinguish from mdstat alone — requires cross-ref with load_info/disk_log
                // For now, flag as needs_verification if not fully populated
                $raids[$currentMd]['health'] = ($configured === $active) ? 'clean' : 'check_status';
                $raids[$currentMd]['member_count'] = $configured;  // Number of configured slots
                $raids[$currentMd]['total_count'] = $active;       // Number of online/active drives

                // Special handling for internal system arrays (md0, md1)
                if ($raids[$currentMd]['type'] === 'internal') {
                    // Internal RAID 1 arrays are designed to handle missing drives
                    // Only flag as problem if ONLY 1 drive is active
                    if ($active === 1) {
                        $raids[$currentMd]['status_note'] = "Internal system array running on single drive. Performance degraded.";
                    } else {
                        $raids[$currentMd]['status_note'] = null;  // Healthy internal array
                    }
                } else {
                    // User RAID arrays: flag for verification if not fully populated
                    if ($configured > $active) {
                        $raids[$currentMd]['status_note'] = "Configured for {$configured} drives, {$active} active. May indicate empty bays or failed drives.";
                    }
                }
            } elseif (preg_match('/^Personalities : (.*)/', $line, $matches)) {
                $context['personalities'] = $matches[1];
            }
        }

        return [
            'data' => $raids,
            'citations' => [
                [
                    'file' => 'dsm/proc/mdstat',
                    'lines' => '1-' . count($lines),
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                ]
            ]
        ];
    }

    private function parseDisks(string $diskString): array
    {
        $disks = [];
        $parts = explode(' ', trim($diskString));
        foreach ($parts as $part) {
            if (preg_match('/(.*)\[(\d+)\]/', $part, $matches)) {
                $disks[] = $matches[1];
            }
        }
        return $disks;
    }

    /**
     * Classify RAID array as internal system or user-created
     * md0 = Internal System RAID 1 (root filesystem)
     * md1 = Internal Swap RAID 1
     * mdX (X>=2) = User-created RAID arrays
     */
    private function classifyArray(string $mdName): string
    {
        if (in_array($mdName, ['md0', 'md1'], true)) {
            return 'internal';
        }
        return 'user';
    }
}
