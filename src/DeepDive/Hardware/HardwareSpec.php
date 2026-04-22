<?php

declare(strict_types=1);

namespace App\DeepDive\Hardware;

/**
 * Hardware specification data model.
 * Encapsulates all extracted hardware information with citations.
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
     * Data source citations
     * Maps extracted fields to their source files with timestamps
     * @var array<string, array{file: string, timestamp: string, lines?: string}>
     */
    public array $citations = [];

    /**
     * Calculate data completeness score (0-100)
     * Indicates how much of the available hardware data was successfully extracted
     */
    public function completenessScore(): float
    {
        $score = 0;
        $maxScore = 0;

        // Device identity (2 fields)
        $maxScore += 2;
        if (!empty($this->model)) $score++;
        if (!empty($this->serial)) $score++;

        // CPU (1 field)
        $maxScore += 1;
        if (!empty($this->cpu['model'])) $score++;

        // RAM (1 field)
        $maxScore += 1;
        if ($this->ram['total_gb'] > 0) $score++;

        // Drive info (2 fields)
        $maxScore += 2;
        if ($this->driveBays['total'] > 0) $score++;
        if (!empty($this->drives)) $score++;

        // RAID (1 field)
        $maxScore += 1;
        if ($this->raidConfig['total_arrays'] > 0) $score++;

        // Volumes (1 field)
        $maxScore += 1;
        if (!empty($this->volumes)) $score++;

        // Expansion (1 field)
        $maxScore += 1;
        if (!empty($this->expansion)) $score++;

        if ($maxScore === 0) {
            return 0;
        }

        return ($score / $maxScore) * 100;
    }

    /**
     * Get human-readable completeness assessment
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
     * @return list<string>
     */
    public function extractedFields(): array
    {
        $fields = [];

        if (!empty($this->model)) $fields[] = 'model';
        if (!empty($this->serial)) $fields[] = 'serial';
        if (!empty($this->location)) $fields[] = 'location';
        if (!empty($this->cpu['model'])) $fields[] = 'cpu';
        if ($this->ram['total_gb'] > 0) $fields[] = 'ram';
        if ($this->uptime_days > 0) $fields[] = 'uptime';
        if ($this->driveBays['total'] > 0) $fields[] = 'drive_bays';
        if (!empty($this->drives)) $fields[] = 'drives';
        if ($this->raidConfig['total_arrays'] > 0) $fields[] = 'raid_config';
        if (!empty($this->volumes)) $fields[] = 'volumes';
        if ($this->expansion['has_expansion']) $fields[] = 'expansion';

        return $fields;
    }

    /**
     * Get list of fields that are missing
     * @return list<string>
     */
    public function missingFields(): array
    {
        $all = [
            'model', 'serial', 'location', 'cpu', 'ram', 'uptime',
            'drive_bays', 'drives', 'raid_config', 'volumes', 'expansion'
        ];

        $extracted = $this->extractedFields();

        return array_values(array_diff($all, $extracted));
    }

    /**
     * Convert to array for report rendering/serialization
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
            'citations' => $this->citations,
            'completeness_score' => $this->completenessScore(),
            'completeness_assessment' => $this->completenessAssessment(),
            'extracted_fields' => $this->extractedFields(),
            'missing_fields' => $this->missingFields(),
        ];
    }
}
