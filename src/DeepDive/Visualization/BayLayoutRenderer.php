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
        // Escape containerName for SVG context
        $containerName = htmlspecialchars($containerName, ENT_QUOTES, 'UTF-8');

        $rows = (int)ceil($totalBays / $columnsPerRow);
        $width = 420;
        $height = 80 + ($rows * 70);
        $svgWidth = $width;
        $svgHeight = $height;

        $svg = "<svg viewBox=\"0 0 $svgWidth $svgHeight\" xmlns=\"http://www.w3.org/2000/svg\" class=\"bay-layout\">\n";
        $svg .= "<style>\n";
        $svg .= $this->getStyles();
        $svg .= "</style>\n";

        // Title
        $svg .= "<text x=\"15\" y=\"25\" class=\"bay-title\">{$containerName}</text>\n";
        $svg .= "<text x=\"15\" y=\"45\" class=\"bay-subtitle\">$totalBays Bays - " . count($drives) . " occupied</text>\n";

        // Create drive mapping
        $driveMap = [];
        foreach ($drives as $drive) {
            $driveMap[$drive['bay']] = $drive;
        }

        // Render bays - sleeker compact design
        $xStart = 20;
        $yStart = 55;
        $bayWidth = 85;
        $bayHeight = 60;
        $xGap = 12;
        $yGap = 15;

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
        // Type-safe extraction with escaping
        $bay = (int)($drive['bay'] ?? 0);
        $serial = htmlspecialchars((string)($drive['serial'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $model = htmlspecialchars((string)($drive['model'] ?? ''), ENT_QUOTES, 'UTF-8');
        $healthStatus = (string)($drive['health_status'] ?? 'unknown');
        $badSectors = (int)($drive['bad_sectors'] ?? 0);
        $installed = (string)($drive['installation_date'] ?? 'Unknown');
        $replacementCount = (int)($drive['replacement_count'] ?? 0);

        // Determine color and icon based on health
        $colorClass = $this->getHealthColorClass($healthStatus, $badSectors);
        $icon = $this->getHealthIcon($healthStatus, $badSectors);

        // Check if recently replaced (< 6 months)
        $recentReplacementBorder = '';
        if ($replacementCount > 0 && $this->isRecentlyReplaced($installed)) {
            $recentReplacementBorder = " stroke=\"#FF9800\" stroke-width=\"2\"";
        }

        // Check if problematic slot (3+ replacements)
        $problemSlotBorder = '';
        if ($replacementCount >= 3) {
            $problemSlotBorder = " stroke=\"#F44336\" stroke-width=\"1.5\"";
        }

        $svg = "<g class=\"bay-item\">\n";

        // Background rectangle
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" class=\"bay-bg $colorClass\"{$recentReplacementBorder}{$problemSlotBorder} />\n";

        // Bay number (top-left)
        $svg .= "  <text x=\"" . ($x + 5) . "\" y=\"" . ($y + 14) . "\" class=\"bay-number\">Slot {$bay}</text>\n";

        // Health icon (top-right)
        $svg .= "  <text x=\"" . ($x + $w - 10) . "\" y=\"" . ($y + 15) . "\" class=\"health-icon\">$icon</text>\n";

        // Serial (middle) - shorter for compact display
        $shortSerial = strlen($serial) > 10 ? substr($serial, 0, 10) : $serial;
        $svg .= "  <text x=\"" . ($x + 5) . "\" y=\"" . ($y + 33) . "\" class=\"bay-serial\">$shortSerial</text>\n";

        // Model (below serial)
        $shortModel = strlen($model) > 10 ? substr($model, 0, 8) . '...' : $model;
        $svg .= "  <text x=\"" . ($x + 5) . "\" y=\"" . ($y + 45) . "\" class=\"bay-model\">$shortModel</text>\n";

        // Bad sectors count if any
        if ($badSectors > 0) {
            $svg .= "  <text x=\"" . ($x + 5) . "\" y=\"" . ($y + 56) . "\" class=\"bay-sectors\">$badSectors s</text>\n";
        }

        // Tooltip (SVG title for hover) - proper component-level escaping
        $tooltipParts = [
            "Slot {$bay}",
            $serial,
            $model,
            "Status: {$healthStatus}",
            "Installed: {$installed}"
        ];
        if ($replacementCount > 0) {
            $tooltipParts[] = "Replacements: {$replacementCount}";
        }
        if ($badSectors > 0) {
            $tooltipParts[] = "Bad Sectors: {$badSectors}";
        }
        $tooltip = implode("\n", $tooltipParts);
        $svg .= "  <title>{$tooltip}</title>\n";

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
