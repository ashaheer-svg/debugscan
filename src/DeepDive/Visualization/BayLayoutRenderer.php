<?php

declare(strict_types=1);

namespace App\DeepDive\Visualization;

/**
 * BayLayoutRenderer: Hardware visualization for NAS bay layouts
 *
 * PURPOSE:
 * Renders physical NAS device layouts showing bay slots as they appear in hardware.
 * Uses a minimal, professional design with:
 * - Device frame visualization
 * - Simple bay slot rectangles
 * - Status indicators (checkmark, warning, critical icons)
 * - Clean, functional aesthetic
 *
 * VISUALIZATION:
 * - Physical device outline (enclosure)
 * - Bay slots in correct physical arrangement
 * - Status icon overlaid on each bay
 * - Bay number label
 * - Minimal color palette (gray, green, yellow, red)
 *
 * STATUS INDICATORS:
 * - ✓ Green: Healthy
 * - ⚠ Yellow: Warning (caution/minor issues)
 * - ! Yellow: Caution (10-50 bad sectors)
 * - ✕ Red: Critical (failed or 100+ sectors)
 * - - Gray: Empty bay
 *
 * @package App\DeepDive\Visualization
 */
final class BayLayoutRenderer
{
    private const STATUS_COLORS = [
        'healthy'  => '#10b981',
        'caution'  => '#f59e0b',
        'warning'  => '#f59e0b',
        'critical' => '#dc2626',
        'empty'    => '#e5e7eb',
    ];

    /**
     * Render hardware device with bay visualization
     *
     * @param string $containerName Device name (e.g., "DS918+", "Main Unit")
     * @param array $drives List of installed drives
     * @param int $totalBays Total bay count
     * @param int $columnsPerRow Bays per row (default: auto-calculate)
     *
     * @return string SVG device visualization
     */
    public function renderBayLayout(
        string $containerName,
        array $drives,
        int $totalBays = 12,
        int $columnsPerRow = 0
    ): string {
        $containerName = htmlspecialchars($containerName, ENT_QUOTES, 'UTF-8');

        if ($columnsPerRow <= 0) {
            $columnsPerRow = $this->getOptimalColumns($totalBays);
        }

        $rows = (int)ceil($totalBays / $columnsPerRow);

        // Hardware device frame dimensions
        $frameWidth = 450;
        $frameHeight = 120 + ($rows * 55);
        $baySlotWidth = 35;
        $baySlotHeight = 45;

        $svg = "<svg viewBox=\"0 0 $frameWidth $frameHeight\" xmlns=\"http://www.w3.org/2000/svg\" class=\"hardware-device\" style=\"max-width:100%;height:auto;\">\n";
        $svg .= "<style>\n";
        $svg .= $this->getStyles();
        $svg .= "</style>\n";

        // Device background
        $svg .= "<rect width=\"$frameWidth\" height=\"$frameHeight\" fill=\"#f9fafb\" />\n";

        // Device header
        $svg .= "<g class=\"device-header\">\n";
        $svg .= "  <text x=\"20\" y=\"30\" class=\"device-title\">{$containerName}</text>\n";
        $svg .= "  <text x=\"20\" y=\"48\" class=\"device-subtitle\">{$totalBays} Bay" . ($totalBays !== 1 ? 's' : '') . " · " . count($drives) . " Installed</text>\n";
        $svg .= "</g>\n";

        // Device enclosure frame
        $enclosureX = 15;
        $enclosureY = 60;
        $enclosureWidth = $frameWidth - 30;
        $enclosureHeight = ($rows * 55) + 20;

        $svg .= "<g class=\"device-enclosure\">\n";
        $svg .= "  <rect x=\"$enclosureX\" y=\"$enclosureY\" width=\"$enclosureWidth\" height=\"$enclosureHeight\" fill=\"none\" stroke=\"#cbd5e1\" stroke-width=\"2\" rx=\"4\" />\n";

        // Create bay mapping
        $driveMap = [];
        foreach ($drives as $drive) {
            $driveMap[$drive['bay']] = $drive;
        }

        // Render bay slots
        $bayStartX = $enclosureX + 15;
        $bayStartY = $enclosureY + 12;
        $bayGapX = 8;
        $bayGapY = 10;

        for ($bay = 1; $bay <= $totalBays; $bay++) {
            $row = (int)floor(($bay - 1) / $columnsPerRow);
            $col = ($bay - 1) % $columnsPerRow;

            $x = $bayStartX + ($col * ($baySlotWidth + $bayGapX));
            $y = $bayStartY + ($row * ($baySlotHeight + $bayGapY));

            if (isset($driveMap[$bay])) {
                $svg .= $this->renderBaySlot($driveMap[$bay], $x, $y, $baySlotWidth, $baySlotHeight);
            } else {
                $svg .= $this->renderEmptyBaySlot($bay, $x, $y, $baySlotWidth, $baySlotHeight);
            }
        }

        $svg .= "</g>\n";

        // Legend
        $svg .= $this->renderLegend();

        $svg .= "</svg>\n";

        return $svg;
    }

    /**
     * Calculate optimal columns based on bay count
     */
    private function getOptimalColumns(int $totalBays): int
    {
        if ($totalBays <= 4) return 4;
        if ($totalBays <= 8) return 4;
        if ($totalBays <= 12) return 4;
        if ($totalBays <= 16) return 4;
        return 5;
    }

    /**
     * Render a single bay slot with drive
     */
    private function renderBaySlot(array $drive, float $x, float $y, float $w, float $h): string
    {
        $bay = (int)($drive['bay'] ?? 0);
        $healthStatus = (string)($drive['health_status'] ?? 'unknown');
        $badSectors = (int)($drive['bad_sectors'] ?? 0);

        // Determine status
        $status = $this->getStatus($healthStatus, $badSectors);
        $statusColor = self::STATUS_COLORS[$status] ?? '#9ca3af';
        $statusIcon = $this->getStatusIcon($status);

        // Bay slot background
        $svg = "<g class=\"bay-slot\" data-bay=\"$bay\">\n";
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" fill=\"#fff\" stroke=\"#d1d5db\" stroke-width=\"1\" rx=\"3\" />\n";

        // Bay number (top-left)
        $svg .= "  <text x=\"" . ($x + 4) . "\" y=\"" . ($y + 12) . "\" class=\"bay-slot-number\">$bay</text>\n";

        // Status icon (top-right with background)
        $iconX = $x + $w - 10;
        $iconY = $y + 8;
        $svg .= "  <circle cx=\"" . ($iconX) . "\" cy=\"" . ($iconY) . "\" r=\"7\" fill=\"$statusColor\" />\n";
        $svg .= "  <text x=\"" . ($iconX) . "\" y=\"" . ($iconY + 3) . "\" class=\"status-icon\" text-anchor=\"middle\" fill=\"white\">$statusIcon</text>\n";

        // Drive info (middle)
        $serial = htmlspecialchars((string)($drive['serial'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $shortSerial = strlen($serial) > 8 ? substr($serial, 0, 7) : $serial;
        $svg .= "  <text x=\"" . ($x + 4) . "\" y=\"" . ($y + 28) . "\" class=\"bay-serial\">$shortSerial</text>\n";

        // Tooltip
        $tooltipText = "Bay $bay\n{$serial}\n" . htmlspecialchars((string)($drive['model'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($badSectors > 0) {
            $tooltipText .= "\nBad Sectors: {$badSectors}";
        }
        $svg .= "  <title>$tooltipText</title>\n";

        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render an empty bay slot
     */
    private function renderEmptyBaySlot(int $bay, float $x, float $y, float $w, float $h): string
    {
        $svg = "<g class=\"bay-slot empty\" data-bay=\"$bay\">\n";
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" fill=\"#f9fafb\" stroke=\"#d1d5db\" stroke-width=\"1\" stroke-dasharray=\"3,2\" rx=\"3\" />\n";
        $svg .= "  <text x=\"" . ($x + $w / 2) . "\" y=\"" . ($y + $h / 2 + 2) . "\" class=\"empty-bay-text\" text-anchor=\"middle\">-</text>\n";
        $svg .= "  <title>Bay $bay - Empty</title>\n";
        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render legend
     */
    private function renderLegend(): string
    {
        return <<<'SVG'
<g class="legend">
  <text x="20" y="0" class="legend-title">Status</text>
  <g class="legend-item">
    <circle cx="30" cy="12" r="5" fill="#10b981" />
    <text x="42" y="16" class="legend-text">Healthy</text>
  </g>
  <g class="legend-item">
    <circle cx="120" cy="12" r="5" fill="#f59e0b" />
    <text x="132" y="16" class="legend-text">Warning</text>
  </g>
  <g class="legend-item">
    <circle cx="220" cy="12" r="5" fill="#dc2626" />
    <text x="232" y="16" class="legend-text">Critical</text>
  </g>
  <g class="legend-item">
    <circle cx="310" cy="12" r="5" fill="#e5e7eb" />
    <text x="322" y="16" class="legend-text">Empty</text>
  </g>
</g>
SVG;
    }

    /**
     * Determine status based on health and bad sectors
     */
    private function getStatus(string $healthStatus, int $badSectors): string
    {
        if ($healthStatus === 'critical' || $badSectors > 100) {
            return 'critical';
        } elseif ($healthStatus === 'warning' || $badSectors > 50) {
            return 'warning';
        } elseif ($healthStatus === 'caution' || $badSectors > 10) {
            return 'caution';
        }
        return 'healthy';
    }

    /**
     * Get status icon
     */
    private function getStatusIcon(string $status): string
    {
        return match ($status) {
            'healthy'  => '✓',
            'caution'  => '!',
            'warning'  => '⚠',
            'critical' => '✕',
            default    => '?',
        };
    }

    /**
     * Get CSS styles
     */
    private function getStyles(): string
    {
        return <<<'CSS'
.hardware-device {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    background: white;
}

.device-header {
    pointer-events: none;
}

.device-title {
    font-size: 16px;
    font-weight: 700;
    fill: #1f2937;
    letter-spacing: -0.3px;
}

.device-subtitle {
    font-size: 12px;
    fill: #6b7280;
    font-weight: 500;
}

.device-enclosure {
    pointer-events: auto;
}

.bay-slot {
    cursor: pointer;
    transition: all 0.2s ease;
}

.bay-slot:hover rect {
    fill: #f3f4f6;
    stroke: #9ca3af;
}

.bay-slot-number {
    font-size: 10px;
    font-weight: 700;
    fill: #374151;
}

.bay-serial {
    font-size: 8px;
    fill: #6b7280;
    font-family: 'Monaco', 'Courier New', monospace;
    font-weight: 500;
}

.empty-bay-text {
    font-size: 14px;
    fill: #d1d5db;
    font-weight: 600;
}

.status-icon {
    font-size: 10px;
    font-weight: 700;
}

.legend {
    transform: translate(0, -25);
}

.legend-title {
    font-size: 11px;
    font-weight: 700;
    fill: #1f2937;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.legend-item {
    pointer-events: none;
}

.legend-text {
    font-size: 10px;
    fill: #6b7280;
    font-weight: 500;
}

CSS;
    }
}
