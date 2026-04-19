<?php

declare(strict_types=1);

namespace App\Parsers;

class BtrfsScrubParser implements ParserInterface
{
    public function parse(string $extractedPath, array &$context): array
    {
        // Look for btrfs scrub logs
        $file = $extractedPath . '/dsm/var/log/syno_check_btrfs_scrub.log';

        // If primary log doesn't exist, look for alternative locations
        if (!file_exists($file)) {
            $altFile = $extractedPath . '/dsm/var/log/btrfs_scrub.log';
            if (file_exists($altFile)) {
                $file = $altFile;
            } else {
                return [
                    'scrub_history' => [],
                    'summary' => [
                        'scrubs_found' => 0,
                        'last_scrub' => null,
                        'overall_status' => 'unknown',
                        'recommendation' => 'No btrfs scrub log found. Filesystem integrity checks may not be enabled.'
                    ],
                    '_tag' => '[BTRFS_HEALTH]'
                ];
            }
        }

        $content = file_get_contents($file);
        $lines = explode("\n", $content);

        $scrub_history = [];
        $current_scrub = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            // Look for scrub start marker
            // Example: "Scrub started on volume1"
            if (preg_match('/Scrub\s+started\s+on\s+(\S+)/i', $line, $m)) {
                if ($current_scrub !== null) {
                    $scrub_history[] = $current_scrub;
                }

                $current_scrub = [
                    'volume' => $m[1],
                    'start_time' => $this->extractTimestamp($line),
                    'end_time' => null,
                    'duration_minutes' => null,
                    'errors' => 0,
                    'status' => 'in_progress',
                    'errors_detail' => []
                ];
            }

            // Look for scrub completion
            // Example: "Scrub completed on 2026-04-08"
            if (preg_match('/Scrub\s+completed/i', $line, $m)) {
                if ($current_scrub !== null) {
                    $current_scrub['end_time'] = $this->extractTimestamp($line);
                    $current_scrub['status'] = 'completed';
                }
            }

            // Look for scrub errors
            // Example: "error: unable to fix bad sector" or "corrupted data"
            if (preg_match('/error|corrupt|bad sector|unreadable/i', $line, $m)) {
                if ($current_scrub !== null) {
                    $current_scrub['errors']++;
                    $current_scrub['errors_detail'][] = $line;
                    $current_scrub['status'] = 'completed_with_errors';
                }
            }

            // Look for COW (Copy-on-Write) status
            // Some systems may log COW settings
            if (preg_match('/Copy\s*on\s*Write|COW\s*enabled|COW\s*disabled/i', $line, $m)) {
                if ($current_scrub !== null) {
                    if (preg_match('/disabled/i', $line)) {
                        $current_scrub['cow_enabled'] = false;
                    } else {
                        $current_scrub['cow_enabled'] = true;
                    }
                }
            }

            // Look for compression info
            if (preg_match('/compression[:\s]+(\w+)/i', $line, $m)) {
                if ($current_scrub !== null) {
                    $current_scrub['compression'] = strtolower($m[1]);
                }
            }

            // Look for RAID profile
            if (preg_match('/profile[:\s]+(raid\d+|single)/i', $line, $m)) {
                if ($current_scrub !== null) {
                    $current_scrub['raid_profile'] = strtolower($m[1]);
                }
            }
        }

        // Don't forget the last scrub
        if ($current_scrub !== null) {
            $scrub_history[] = $current_scrub;
        }

        // Compute durations if we have timestamps
        foreach ($scrub_history as &$scrub) {
            if ($scrub['start_time'] && $scrub['end_time']) {
                try {
                    $start = new \DateTime($scrub['start_time']);
                    $end = new \DateTime($scrub['end_time']);
                    $diff = $start->diff($end);
                    $scrub['duration_minutes'] = ($diff->h * 60) + $diff->i;
                } catch (\Exception $e) {
                    // If timestamp parsing fails, skip duration
                }
            }
        }

        return [
            'data' => [
                'scrub_history' => $scrub_history,
                'summary' => $this->generateSummary($scrub_history),
                '_tag' => '[BTRFS_HEALTH]'
            ],
            'citations' => [
                [
                    'file' => str_replace($extractedPath . '/', '', $file),
                    'lines' => '1-' . count($lines),
                    'timestamp' => date('Y-m-d H:i:s', filemtime($file))
                ]
            ]
        ];
    }

    private function extractTimestamp(string $line): ?string
    {
        // Try to extract ISO 8601 style: YYYY-MM-DD or HH:MM:SS
        if (preg_match('/(\d{4})-(\d{2})-(\d{2})(?:T(\d{2}):(\d{2}):(\d{2}))?/', $line, $m)) {
            $date = "{$m[1]}-{$m[2]}-{$m[3]}";
            $time = isset($m[4]) ? "T{$m[4]}:{$m[5]}:{$m[6]}Z" : '';
            return $date . $time;
        }

        // Try common date formats
        if (preg_match('/(\d{4})\/(\d{2})\/(\d{2})\s+(\d{2}):(\d{2}):(\d{2})/', $line, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}Z";
        }

        return null;
    }

    private function generateSummary(array $scrub_history): array
    {
        $summary = [
            'scrubs_found' => count($scrub_history),
            'last_scrub' => null,
            'last_scrub_status' => null,
            'last_scrub_errors' => 0,
            'last_scrub_age_days' => null,
            'scrub_needed' => true,
            'overall_status' => 'unknown',
            'recommendation' => ''
        ];

        if (empty($scrub_history)) {
            $summary['overall_status'] = 'unknown';
            $summary['recommendation'] = 'No btrfs scrub history found. Enable regular scrubbing for filesystem integrity.';
            return $summary;
        }

        // Get last scrub
        $last_scrub = end($scrub_history);
        $summary['last_scrub'] = $last_scrub['start_time'] ?? null;
        $summary['last_scrub_status'] = $last_scrub['status'] ?? 'unknown';
        $summary['last_scrub_errors'] = $last_scrub['errors'] ?? 0;

        // Calculate age of last scrub
        if ($summary['last_scrub']) {
            try {
                $last_date = new \DateTime($summary['last_scrub']);
                $now = new \DateTime();
                $diff = $last_date->diff($now);
                $summary['last_scrub_age_days'] = ($diff->days);
                $summary['scrub_needed'] = $summary['last_scrub_age_days'] > 30;
            } catch (\Exception $e) {
                // If parsing fails, can't determine age
            }
        }

        // Overall status determination
        if ($summary['last_scrub_errors'] > 0) {
            $summary['overall_status'] = 'critical';
            $summary['recommendation'] = 'Btrfs scrub found errors. Filesystem may have corruption. Investigate and run repair if needed.';
        } elseif ($summary['last_scrub_status'] === 'completed') {
            if ($summary['scrub_needed']) {
                $summary['overall_status'] = 'warning';
                $summary['recommendation'] = sprintf(
                    'Last scrub was %d days ago. Enable regular scrubbing (weekly/monthly) for ongoing integrity checks.',
                    $summary['last_scrub_age_days']
                );
            } else {
                $summary['overall_status'] = 'healthy';
                $summary['recommendation'] = 'Btrfs filesystem is healthy with recent scrub completion and no errors.';
            }
        } else {
            $summary['overall_status'] = 'unknown';
            $summary['recommendation'] = 'Could not determine scrub status. Check btrfs logs manually.';
        }

        return $summary;
    }
}
