<?php

declare(strict_types=1);

namespace App\DeepDive\Parsers;

/**
 * File Availability Validator: Pre-flight checks for required data sources
 *
 * PURPOSE:
 * Validates that required data files are present in extracted bundle before parsing.
 * Provides transparency about data completeness and enables graceful degradation.
 *
 * RESPONSIBILITIES:
 * 1. Scan bundle for all expected data files
 * 2. Categorize files as: REQUIRED (parsing fails without), OPTIONAL (nice to have)
 * 3. Generate manifest showing: found | missing | alternative locations
 * 4. Report data completeness percentage
 * 5. Enable downstream parsers to skip missing files gracefully
 *
 * FILE CATEGORIES:
 * - CRITICAL: System info, logs (needed for rules evaluation)
 * - POWER: DMI/IPMI files (power anomaly detection)
 * - HARDWARE: Hardware specifications (completeness scoring)
 * - OPTIONAL: Secondary files that enhance analysis but aren't required
 *
 * USAGE:
 * $validator = new FileAvailabilityValidator();
 * $manifest = $validator->validateBundle($extractedPath);
 * // Returns array with: available_files, missing_files, completeness_pct, issues
 *
 * @package App\DeepDive\Parsers
 */
class FileAvailabilityValidator
{
    /**
     * File requirements by category
     * Maps file pattern to required status and parser that uses it
     */
    private const FILE_REQUIREMENTS = [
        // === CRITICAL: System logs (rules evaluation depends on these) ===
        'critical' => [
            'var/log/messages' => 'System messages log',
            'var/log/kern.log' => 'Kernel log',
            'var/log/scemd' => 'Storage controller events',
            'var/log/mdstat' => 'RAID status snapshots',
        ],

        // === POWER: Power supply data (power anomaly detection) ===
        'power' => [
            'dsm/result/dmidecode.result' => 'DMI Type 39 power supply info',
            'dsm/result/ipmi_sensors.result' => 'IPMI voltage/current sensors',
            'dsm/result/ipmi_event_log.result' => 'IPMI System Event Log',
            'dsm/result/ipmi_chassis_status.result' => 'IPMI chassis power state',
        ],

        // === HARDWARE: System specs (hardware extraction) ===
        'hardware' => [
            'etc/hostname' => 'System hostname',
            'proc/cpuinfo' => 'CPU information',
            'proc/meminfo' => 'Memory information',
            'dsm/etc/syno_hw_version' => 'Hardware model',
            'proc/mdstat' => 'RAID array status',
        ],

        // === OPTIONAL: Secondary files that enhance analysis ===
        'optional' => [
            'etc/rc.conf' => 'System configuration',
            'proc/diskstats' => 'Disk I/O statistics',
            'proc/net/dev' => 'Network device statistics',
            'var/log/upgrade.log' => 'Upgrade history',
        ],
    ];

    /**
     * Alternative file paths to try if primary path missing
     */
    private const ALTERNATIVE_PATHS = [
        'dsm/result/dmidecode.result' => [
            'result/dmidecode.result',
            'dmidecode.result',
            '*/result/dmidecode.result',
        ],
        'dsm/result/ipmi_sensors.result' => [
            'result/ipmi_sensors.result',
            'ipmi_sensors.result',
            '*/result/ipmi_sensors.result',
        ],
        'var/log/messages' => [
            'dsm/log/messages',
            'log/messages',
        ],
    ];

    /**
     * Validate bundle file availability
     *
     * Scans bundle directory for all expected files and generates
     * a manifest showing what was found, what's missing, and overall
     * data completeness.
     *
     * @param string $bundlePath Extracted bundle directory
     *
     * @return array{
     *     available: array,
     *     missing: array,
     *     critical_available: int,
     *     power_available: int,
     *     hardware_available: int,
     *     total_critical: int,
     *     total_power: int,
     *     total_hardware: int,
     *     completeness_pct: float,
     *     assessment: string,
     *     issues: array
     * }
     */
    public function validateBundle(string $bundlePath): array
    {
        $available = [];
        $missing = [];
        $alternatives = [];

        // Check each required file
        foreach (self::FILE_REQUIREMENTS as $category => $files) {
            foreach ($files as $path => $description) {
                $found = $this->findFile($bundlePath, $path);

                if ($found) {
                    $available[$path] = [
                        'category' => $category,
                        'description' => $description,
                        'actual_path' => $found,
                        'size_bytes' => filesize($found) ?: 0,
                        'timestamp' => filemtime($found) ?: time(),
                    ];
                } else {
                    // Check for alternatives
                    $altPath = $this->tryAlternatives($bundlePath, $path);
                    if ($altPath) {
                        $alternatives[$path] = [
                            'category' => $category,
                            'description' => $description,
                            'found_at' => $altPath,
                            'size_bytes' => filesize($altPath) ?: 0,
                        ];
                    } else {
                        $missing[$path] = [
                            'category' => $category,
                            'description' => $description,
                        ];
                    }
                }
            }
        }

        // Calculate completeness metrics per category
        $metrics = $this->calculateMetrics($available, $missing, $alternatives);

        // Generate assessment
        $assessment = $this->assessCompleteness(
            $metrics['total_critical'],
            $metrics['critical_available'],
            $metrics['total_power'],
            $metrics['power_available']
        );

        // Identify data quality issues
        $issues = $this->identifyIssues($available, $missing, $metrics);

        return [
            'available' => $available,
            'missing' => $missing,
            'alternatives_found' => $alternatives,
            'critical_available' => $metrics['critical_available'],
            'power_available' => $metrics['power_available'],
            'hardware_available' => $metrics['hardware_available'],
            'total_critical' => $metrics['total_critical'],
            'total_power' => $metrics['total_power'],
            'total_hardware' => $metrics['total_hardware'],
            'completeness_pct' => round($metrics['completeness_pct'], 1),
            'assessment' => $assessment,
            'issues' => $issues,
        ];
    }

    /**
     * Find file in bundle, checking primary path
     *
     * @param string $bundlePath Base bundle directory
     * @param string $relativePath Expected relative path from bundle root
     *
     * @return string|null Full path if found, null otherwise
     */
    private function findFile(string $bundlePath, string $relativePath): ?string
    {
        $fullPath = $bundlePath . '/' . ltrim($relativePath, '/');

        if (file_exists($fullPath) && is_file($fullPath)) {
            return $fullPath;
        }

        return null;
    }

    /**
     * Try alternative paths for a file
     *
     * @param string $bundlePath Base bundle directory
     * @param string $primaryPath Original expected path
     *
     * @return string|null Path to file if found via alternatives
     */
    private function tryAlternatives(string $bundlePath, string $primaryPath): ?string
    {
        if (!isset(self::ALTERNATIVE_PATHS[$primaryPath])) {
            return null;
        }

        foreach (self::ALTERNATIVE_PATHS[$primaryPath] as $altPattern) {
            // Direct path check
            if (strpos($altPattern, '*') === false) {
                $fullPath = $bundlePath . '/' . ltrim($altPattern, '/');
                if (file_exists($fullPath) && is_file($fullPath)) {
                    return $fullPath;
                }
            } else {
                // Glob pattern
                $globPath = $bundlePath . '/' . ltrim($altPattern, '/');
                $matches = glob($globPath);
                if ($matches && count($matches) > 0) {
                    foreach ($matches as $match) {
                        if (is_file($match)) {
                            return $match;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Calculate availability metrics by category
     *
     * @param array $available Found files
     * @param array $missing Missing files
     * @param array $alternatives Alternatives found
     *
     * @return array Metrics with counts and percentages
     */
    private function calculateMetrics(array $available, array $missing, array $alternatives): array
    {
        $critical_avail = 0;
        $power_avail = 0;
        $hardware_avail = 0;

        foreach ($available as $file) {
            match ($file['category']) {
                'critical' => $critical_avail++,
                'power' => $power_avail++,
                'hardware' => $hardware_avail++,
                default => null,
            };
        }

        // Also count alternatives as available for metrics
        foreach ($alternatives as $file) {
            match ($file['category']) {
                'critical' => $critical_avail++,
                'power' => $power_avail++,
                'hardware' => $hardware_avail++,
                default => null,
            };
        }

        $total_files = count(self::FILE_REQUIREMENTS['critical']) +
                      count(self::FILE_REQUIREMENTS['power']) +
                      count(self::FILE_REQUIREMENTS['hardware']) +
                      count(self::FILE_REQUIREMENTS['optional']);

        $found_count = count($available) + count($alternatives);
        $completeness = ($found_count / $total_files) * 100;

        return [
            'critical_available' => $critical_avail,
            'power_available' => $power_avail,
            'hardware_available' => $hardware_avail,
            'total_critical' => count(self::FILE_REQUIREMENTS['critical']),
            'total_power' => count(self::FILE_REQUIREMENTS['power']),
            'total_hardware' => count(self::FILE_REQUIREMENTS['hardware']),
            'completeness_pct' => $completeness,
        ];
    }

    /**
     * Generate data quality assessment
     *
     * @param int $totalCritical Total critical files expected
     * @param int $availableCritical Critical files found
     * @param int $totalPower Total power files expected
     * @param int $availablePower Power files found
     *
     * @return string Human-readable assessment
     */
    private function assessCompleteness(
        int $totalCritical,
        int $availableCritical,
        int $totalPower,
        int $availablePower
    ): string {
        $criticalPct = ($availableCritical / $totalCritical) * 100;
        $powerPct = ($availablePower / $totalPower) * 100;

        // Critical assessment: can we evaluate rules?
        if ($availableCritical < ($totalCritical * 0.7)) {
            return 'CRITICAL: Insufficient system logs for rule evaluation';
        }

        // Power assessment: can we detect power issues?
        if ($availablePower < 1) {
            $power_status = 'No power data available';
        } elseif ($availablePower < 2) {
            $power_status = 'Limited power data (primary only)';
        } else {
            $power_status = 'Good power data coverage';
        }

        return $criticalPct >= 90
            ? "Complete: {$power_status}"
            : "Partial: {$power_status} ({$criticalPct}% critical logs)";
    }

    /**
     * Identify data quality issues
     *
     * @param array $available Found files
     * @param array $missing Missing files
     * @param array $metrics Calculated metrics
     *
     * @return array List of identified issues
     */
    private function identifyIssues(array $available, array $missing, array $metrics): array
    {
        $issues = [];

        // Missing critical system logs
        foreach (self::FILE_REQUIREMENTS['critical'] as $path => $desc) {
            if (isset($missing[$path])) {
                $issues[] = [
                    'severity' => 'WARNING',
                    'message' => "Critical file missing: {$desc}",
                    'file' => $path,
                    'impact' => 'Rules evaluation may be incomplete',
                ];
            }
        }

        // Missing power data
        $powerMissing = array_count_values(
            array_column($missing, 'category')
        )['power'] ?? 0;

        if ($powerMissing > 2) {
            $issues[] = [
                'severity' => 'INFO',
                'message' => "Multiple power data files missing ({$powerMissing})",
                'impact' => 'Power anomaly detection degraded',
            ];
        }

        // Very low hardware data
        if ($metrics['hardware_available'] < 2) {
            $issues[] = [
                'severity' => 'INFO',
                'message' => 'Minimal hardware information available',
                'impact' => 'Hardware completeness score will be low',
            ];
        }

        return $issues;
    }

    /**
     * Get human-readable file category name
     *
     * @param string $category Category identifier
     *
     * @return string Display name
     */
    public static function categoryName(string $category): string
    {
        return match ($category) {
            'critical' => 'Critical System Logs',
            'power' => 'Power Supply Data',
            'hardware' => 'Hardware Specifications',
            'optional' => 'Optional Data',
            default => 'Unknown',
        };
    }
}
