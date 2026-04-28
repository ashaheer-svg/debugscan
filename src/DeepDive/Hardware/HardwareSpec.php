<?php

declare(strict_types=1);

namespace App\DeepDive\Hardware;

/**
 * Hardware Specification Data Model & Analytics
 *
 * PURPOSE:
 * Comprehensive data model for storing all extracted hardware information
 * from Synology NAS systems (DSM 6.x and DSM 7.x). Includes device identity,
 * CPU/RAM specs, drive bays, RAID configuration, volumes, and failure analysis.
 *
 * RESPONSIBILITIES:
 * - Store extracted hardware data in structured format
 * - Maintain citations/sources for all extracted fields (data lineage)
 * - Calculate completeness score (percentage of available data extracted)
 * - Provide extracted/missing fields lists for analytics
 * - Serialize to array for template rendering
 *
 * DATA SECTIONS:
 * 1. Device Identity: model, serial number, location
 * 2. CPU Info: model, cores, threads
 * 3. Memory: total and available RAM in GB
 * 4. Uptime: system uptime in days
 * 5. Drive Bays: total count, used count, expansion unit count
 * 6. Drives Array: detailed specs for each physical drive
 * 7. Drive History: all drives ever seen (including removed/replaced)
 * 8. RAID Config: arrays, degradation status, rebuilding status
 * 9. Volumes: storage pools and volumes
 * 10. Expansion: info on expansion units attached to main NAS
 * 11. Failures: RAID failures with classification (historical vs current)
 * 12. Failure Patterns: systemic vs staggered failure analysis
 * 13. Raid Failure Logs: raw events from system logs with timestamps
 * 14. Citations: source files and timestamps for extracted fields
 *
 * COMPLETENESS SCORING:
 * Calculates 0-100 score based on:
 *   - Device identity (model, serial)
 *   - CPU info
 *   - RAM
 *   - Drive bays
 *   - Drives list
 *   - RAID configuration
 *   - Volumes
 *   - Expansion units
 *
 * Assessments:
 * - 100% → Complete
 * - 80-99% → Good (most data available)
 * - 50-79% → Partial (some data missing, typical for DSM 6.x)
 * - <50% → Incomplete
 *
 * DATA LINEAGE:
 * Citations field maps each extracted field to source:
 *   - file: Source file path (e.g., /proc/version)
 *   - timestamp: When extracted (ISO 8601)
 *   - lines: Optional line numbers containing data
 *
 * USAGE:
 * HardwareSpecExtractor populates this object during extraction phase
 * ReportRenderer consumes it via toArray() for template rendering
 *
 * @package App\DeepDive\Hardware
 * @final Immutable data model, not meant for subclassing
 */
final class HardwareSpec
{
    /**
     * Device identity
     */
    public string $model = '';
    public string $serial = '';
    public string $location = '';

    /**
     * CPU and RAM
     */
    public array $cpu = [
        'model' => '',
        'cores' => 0,
        'threads' => 0,
    ];

    public array $ram = [
        'total_gb' => 0,
        'available_gb' => 0,
    ];

    /**
     * Uptime
     */
    public float $uptime_days = 0;

    /**
     * Drive Bays
     */
    public array $driveBays = [
        'total' => 0,
        'used' => 0,
        'expansion_count' => 0,
    ];

    /**
     * Drives array: List of physical drives
     * @var array<int, array<string, mixed>>
     */
    public array $drives = [];

    /**
     * Drive change history: All drives seen historically (including removed/disconnected drives)
     * Contains installation dates and replacement timelines
     * @var array<string, array<string, mixed>>
     */
    public array $driveHistory = [];

    /**
     * RAID Configuration
     */
    public array $raidConfig = [
        'arrays' => [],
        'total_arrays' => 0,
        'degraded_arrays' => 0,
        'rebuilding_arrays' => 0,
    ];

    /**
     * Volumes
     */
    public array $volumes = [];

    /**
     * Expansion unit info - supports multiple expansion units
     * @var array<int, array<string, mixed>>
     */
    public array $expansion = [];

    /**
     * RAID failure analysis - classified failures with context
     * Includes historical vs current, replacements, patterns
     * @var array<string, array<string, mixed>>
     */
    public array $failures = [];

    /**
     * Failure pattern analysis
     * Detects systemic vs staggered failures per RAID array
     * @var array<string, array<string, mixed>>
     */
    public array $failurePatterns = [];

    /**
     * Raw RAID failure log events
     * Extracted from /var/log/messages with timestamps
     * @var array<int, array<string, mixed>>
     */
    public array $raidFailureLogs = [];

    /**
     * Data source citations
     * Maps extracted fields to their source files with timestamps
     * @var array<string, array{file: string, timestamp: string, lines?: string}>
     */
    public array $citations = [];

    /**
     * Calculate data completeness score (0-100 percentage)
     *
     * SCORING ALGORITHM:
     * Evaluates 11 major hardware data categories:
     *   1. Device identity (model, serial) → 2 points
     *   2. CPU model → 1 point
     *   3. RAM (total GB) → 1 point
     *   4. Drive bays (total count, drives array) → 2 points
     *   5. RAID config (arrays) → 1 point
     *   6. Volumes → 1 point
     *   7. Expansion units → 1 point
     *
     * Total possible: 11 points
     * Returns: (points_achieved / 11) * 100
     *
     * DSM VERSION NOTES:
     * - DSM 7.x typically scores 80-100% (full data available)
     * - DSM 6.x typically scores 50-79% (some fields unavailable)
     * - Rare/minimal installs may score <50%
     *
     * @return float Completeness percentage (0.0 to 100.0)
     */
    public function completenessScore(): float
    {
        $score = 0;
        $maxScore = 0;

        // === Device identity (2 points) ===
        $maxScore += 2;
        if (!empty($this->model)) $score++;
        if (!empty($this->serial)) $score++;

        // === CPU (1 point) ===
        $maxScore += 1;
        if (!empty($this->cpu['model'])) $score++;

        // === RAM (1 point) ===
        $maxScore += 1;
        if ($this->ram['total_gb'] > 0) $score++;

        // === Drive info (2 points) ===
        $maxScore += 2;
        if ($this->driveBays['total'] > 0) $score++;
        if (!empty($this->drives)) $score++;

        // === RAID (1 point) ===
        $maxScore += 1;
        if ($this->raidConfig['total_arrays'] > 0) $score++;

        // === Volumes (1 point) ===
        $maxScore += 1;
        if (!empty($this->volumes)) $score++;

        // === Expansion (1 point) ===
        $maxScore += 1;
        if (!empty($this->expansion)) $score++;

        // Avoid division by zero
        if ($maxScore === 0) {
            return 0;
        }

        return ($score / $maxScore) * 100;
    }

    /**
     * Get human-readable assessment of data completeness
     *
     * ASSESSMENT LEVELS:
     * - 100% → "Complete - All hardware data extracted"
     * - 80-99% → "Good - Most hardware data available"
     * - 50-79% → "Partial - Some hardware data missing (likely DSM 6.x)"
     * - <50% → "Incomplete - Limited hardware data available"
     *
     * Used for UI display and analysis reports to help understand
     * what data is available vs. what's missing from the extraction
     *
     * @return string Human-readable assessment message
     */
    public function completenessAssessment(): string
    {
        $score = $this->completenessScore();

        if ($score === 100) {
            return 'Complete - All hardware data extracted';
        } elseif ($score >= 80) {
            return 'Good - Most hardware data available';
        } elseif ($score >= 50) {
            return 'Partial - Some hardware data missing (likely DSM 6.x)';
        } else {
            return 'Incomplete - Limited hardware data available';
        }
    }

    /**
     * Get list of fields that were successfully extracted
     *
     * RETURN VALUE:
     * Array of field names that contain non-empty data
     * Examples: ['model', 'serial', 'cpu', 'ram', 'drives']
     *
     * LOGIC:
     * Checks each major field for non-empty values:
     * - String fields: !empty()
     * - Numeric fields: > 0
     * - Array fields: !empty()
     *
     * @return list<string> Field names with extracted data
     */
    public function extractedFields(): array
    {
        $fields = [];

        // Device identity
        if (!empty($this->model)) $fields[] = 'model';
        if (!empty($this->serial)) $fields[] = 'serial';
        if (!empty($this->location)) $fields[] = 'location';

        // CPU and RAM
        if (!empty($this->cpu['model'])) $fields[] = 'cpu';
        if ($this->ram['total_gb'] > 0) $fields[] = 'ram';

        // Uptime
        if ($this->uptime_days > 0) $fields[] = 'uptime';

        // Drive and RAID
        if ($this->driveBays['total'] > 0) $fields[] = 'drive_bays';
        if (!empty($this->drives)) $fields[] = 'drives';
        if ($this->raidConfig['total_arrays'] > 0) $fields[] = 'raid_config';

        // Storage
        if (!empty($this->volumes)) $fields[] = 'volumes';

        // Expansion
        if ($this->expansion['has_expansion'] ?? false) $fields[] = 'expansion';

        return $fields;
    }

    /**
     * Get list of fields that are missing or empty
     *
     * RETURN VALUE:
     * Array of field names that contain no data or are empty
     * Example: ['location', 'uptime', 'raid_config']
     *
     * LOGIC:
     * Derives missing fields by comparing all known fields
     * against successfully extracted fields
     *
     * USAGE:
     * Report generation can highlight missing data to user
     * for data quality assessment
     *
     * @return list<string> Field names with missing data
     */
    public function missingFields(): array
    {
        // All known hardware fields
        $all = [
            'model', 'serial', 'location', 'cpu', 'ram', 'uptime',
            'drive_bays', 'drives', 'raid_config', 'volumes', 'expansion'
        ];

        // Get fields that were extracted
        $extracted = $this->extractedFields();

        // Return the difference (missing fields)
        return array_values(array_diff($all, $extracted));
    }

    /**
     * Convert data model to array for rendering/serialization
     *
     * RETURN STRUCTURE:
     * Returns complete hardware specification as associative array
     * including computed fields (completeness score, extracted/missing fields)
     *
     * USAGE:
     * - Template rendering: {{ hardware.toArray() }}
     * - API responses: json_encode($hardware->toArray())
     * - Serialization: serialize($hardware->toArray())
     *
     * COMPUTED FIELDS:
     * - completeness_score: Percentage (0-100)
     * - completeness_assessment: Human-readable string
     * - extracted_fields: Array of populated field names
     * - missing_fields: Array of empty field names
     *
     * @return array<string, mixed> All hardware data as array
     */
    public function toArray(): array
    {
        return [
            'model' => $this->model,
            'serial' => $this->serial,
            'location' => $this->location,
            'cpu' => $this->cpu,
            'ram' => $this->ram,
            'uptime_days' => $this->uptime_days,
            'drive_bays' => $this->driveBays,
            'drives' => $this->drives,
            'raid_config' => $this->raidConfig,
            'volumes' => $this->volumes,
            'expansion' => $this->expansion,
            'failures' => $this->failures,
            'failure_patterns' => $this->failurePatterns,
            'raid_failure_logs' => $this->raidFailureLogs,
            'citations' => $this->citations,
            'completeness_score' => $this->completenessScore(),
            'completeness_assessment' => $this->completenessAssessment(),
            'extracted_fields' => $this->extractedFields(),
            'missing_fields' => $this->missingFields(),
        ];
    }
}
