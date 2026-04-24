<?php

declare(strict_types=1);

namespace App\DeepDive\Visualization;

/**
 * Renders drive bay layout diagrams as SVG
 * Supports various NAS models with different bay configurations
 * Color-codes by health status and shows replacement history
 */
final class BayLayoutRenderer
{
    /**
     * Render bay layout for a container/unit
     */
    public function renderBayLayout(
        string $containerName,
        array $drives,
        int $totalBays = 12,
        int $columnsPerRow = 4
    ): string {
        $rows = (int)ceil($totalBays / $columnsPerRow);
        $width = 600;
        $height = 100 + ($rows * 90);
        $svgWidth = $width;
        $svgHeight = $height;

        $svg = "<svg viewBox=\"0 0 $svgWidth $svgHeight\" xmlns=\"http://www.w3.org/2000/svg\" class=\"bay-layout\">\n";
        $svg .= "<style>\n";
        $svg .= $this->getStyles();
        $svg .= "</style>\n";

        // Title
        $svg .= "<text x=\"20\" y=\"30\" class=\"bay-title\">$containerName</text>\n";
        $svg .= "<text x=\"20\" y=\"55\" class=\"bay-subtitle\">$totalBays Bays - Slots: " . count($drives) . " occupied</text>\n";

        // Create drive mapping
        $driveMap = [];
        foreach ($drives as $drive) {
            $driveMap[$drive['bay']] = $drive;
        }

        // Render bays
        $xStart = 40;
        $yStart = 80;
        $bayWidth = 120;
        $bayHeight = 70;
        $xGap = 20;
        $yGap = 20;

        for ($bay = 1; $bay <= $totalBays; $bay++) {
            $row = (int)floor(($bay - 1) / $columnsPerRow);
            $col = ($bay - 1) % $columnsPerRow;

            $x = $xStart + ($col * ($bayWidth + $xGap));
            $y = $yStart + ($row * ($bayHeight + $yGap));

            if (isset($driveMap[$bay])) {
                $svg .= $this->renderBay($driveMap[$bay], $x, $y, $bayWidth, $bayHeight);
            } else {
                $svg .= $this->renderEmptyBay($bay, $x, $y, $bayWidth, $bayHeight);
            }
        }

        $svg .= "</svg>\n";

        return $svg;
    }

    /**
     * Render a single occupied bay
     */
    private function renderBay(array $drive, float $x, float $y, float $w, float $h): string {
        $bay = $drive['bay'];
        $serial = $drive['serial'] ?? 'Unknown';
        $model = $drive['model'] ?? '';
        $healthStatus = $drive['health_status'] ?? 'unknown';
        $badSectors = (int)($drive['bad_sectors'] ?? 0);
        $installed = $drive['installation_date'] ?? 'Unknown';
        $replacementCount = (int)($drive['replacement_count'] ?? 0);

        // Determine color and icon based on health
        $colorClass = $this->getHealthColorClass($healthStatus, $badSectors);
        $icon = $this->getHealthIcon($healthStatus, $badSectors);

        // Check if recently replaced (< 6 months)
        $recentReplacementBorder = '';
        if ($replacementCount > 0 && $this->isRecentlyReplaced($installed)) {
            $recentReplacementBorder = " stroke=\"#FF9800\" stroke-width=\"3\"";
        }

        // Check if problematic slot (3+ replacements)
        $problemSlotBorder = '';
        if ($replacementCount >= 3) {
            $problemSlotBorder = " stroke=\"#F44336\" stroke-width=\"2\"";
        }

        $svg = "<g class=\"bay-item\">\n";

        // Background rectangle
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" class=\"bay-bg $colorClass\"{$recentReplacementBorder}{$problemSlotBorder} />\n";

        // Bay number (top-left)
        $svg .= "  <text x=\"" . ($x + 8) . "\" y=\"" . ($y + 18) . "\" class=\"bay-number\">Slot $bay</text>\n";

        // Health icon (top-right)
        $svg .= "  <text x=\"" . ($x + $w - 15) . "\" y=\"" . ($y + 20) . "\" class=\"health-icon\">$icon</text>\n";

        // Serial (middle)
        $shortSerial = strlen($serial) > 12 ? substr($serial, 0, 12) : $serial;
        $svg .= "  <text x=\"" . ($x + 8) . "\" y=\"" . ($y + 38) . "\" class=\"bay-serial\">$shortSerial</text>\n";

        // Model (below serial)
        $shortModel = strlen($model) > 12 ? substr($model, 0, 10) . '...' : $model;
        $svg .= "  <text x=\"" . ($x + 8) . "\" y=\"" . ($y + 52) . "\" class=\"bay-model\">$shortModel</text>\n";

        // Bad sectors count if any
        if ($badSectors > 0) {
            $svg .= "  <text x=\"" . ($x + 8) . "\" y=\"" . ($y + 64) . "\" class=\"bay-sectors\">$badSectors sectors</text>\n";
        }

        // Tooltip (SVG title for hover)
        $tooltip = htmlspecialchars("Slot $bay\n$serial\n$model\nStatus: $healthStatus\nInstalled: $installed");
        if ($replacementCount > 0) {
            $tooltip .= htmlspecialchars("\nReplacements: $replacementCount");
        }
        if ($badSectors > 0) {
            $tooltip .= htmlspecialchars("\nBad Sectors: $badSectors");
        }
        $svg .= "  <title>$tooltip</title>\n";

        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render an empty bay
     */
    private function renderEmptyBay(int $bay, float $x, float $y, float $w, float $h): string {
        $svg = "<g class=\"bay-item\">\n";
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" class=\"bay-bg bay-empty\" stroke=\"#999\" stroke-dasharray=\"5,5\" />\n";
        $svg .= "  <text x=\"" . ($x + 8) . "\" y=\"" . ($y + 18) . "\" class=\"bay-number\">Slot $bay</text>\n";
        $svg .= "  <text x=\"" . ($x + 20) . "\" y=\"" . ($y + 40) . "\" class=\"bay-empty-text\">Empty</text>\n";
        $svg .= "  <title>Bay $bay - Empty</title>\n";
        $svg .= "</g>\n";
        return $svg;
    }

    /**
     * Get CSS color class based on health status
     */
    private function getHealthColorClass(string $status, int $badSectors): string {
        if ($status === 'critical' || $badSectors > 100) {
            return 'bay-critical';
        } elseif ($status === 'warning' || $badSectors > 50) {
            return 'bay-warning';
        } elseif ($status === 'caution' || $badSectors > 10) {
            return 'bay-caution';
        }
        return 'bay-healthy';
    }

    /**
     * Get health icon/emoji based on status
     */
    private function getHealthIcon(string $status, int $badSectors): string {
        if ($status === 'critical' || $badSectors > 100) {
            return '✕';
        } elseif ($status === 'warning' || $badSectors > 50) {
            return '⚠';
        } elseif ($status === 'caution' || $badSectors > 10) {
            return '⚠';
        }
        return '✓';
    }

    /**
     * Check if drive was installed recently (< 6 months)
     */
    private function isRecentlyReplaced(string $dateStr): bool {
        if (!$dateStr || $dateStr === 'Unknown') {
            return false;
        }

        try {
            $date = \DateTime::createFromFormat('Y/m/d H:i:s', $dateStr);
            if (!$date) {
                return false;
            }

            $sixMonthsAgo = new \DateTime('-6 months');
            return $date > $sixMonthsAgo;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Get CSS styles for bay layout
     */
    private function getStyles(): string {
        return <<<'CSS'
.bay-layout {
    font-family: Arial, sans-serif;
    background: #f5f5f5;
    padding: 10px;
}

.bay-title {
    font-size: 18px;
    font-weight: bold;
    fill: #333;
}

.bay-subtitle {
    font-size: 12px;
    fill: #666;
}

.bay-item {
    cursor: pointer;
    transition: opacity 0.2s;
}

.bay-item:hover rect {
    opacity: 0.8;
}

.bay-bg {
    rx: 4;
    ry: 4;
}

.bay-healthy {
    fill: #4CAF50;
    stroke: #2E7D32;
    stroke-width: 1;
}

.bay-caution {
    fill: #FFC107;
    stroke: #F57F17;
    stroke-width: 1;
}

.bay-warning {
    fill: #FF9800;
    stroke: #E65100;
    stroke-width: 2;
}

.bay-critical {
    fill: #F44336;
    stroke: #B71C1C;
    stroke-width: 2;
}

.bay-empty {
    fill: #EEEEEE;
    stroke: #999;
}

.bay-number {
    font-size: 10px;
    font-weight: bold;
    fill: white;
}

.bay-serial {
    font-size: 9px;
    fill: white;
    font-family: monospace;
}

.bay-model {
    font-size: 8px;
    fill: white;
}

.bay-sectors {
    font-size: 8px;
    fill: white;
    font-weight: bold;
}

.bay-empty-text {
    font-size: 12px;
    fill: #999;
    font-weight: bold;
}

.health-icon {
    font-size: 18px;
    fill: white;
}

CSS;
    }
}
