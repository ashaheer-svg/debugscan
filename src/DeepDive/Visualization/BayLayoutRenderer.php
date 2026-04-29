<?php

declare(strict_types=1);

namespace App\DeepDive\Visualization;

/**
 * BayLayoutRenderer: Physical NAS enclosure visualization
 *
 * PURPOSE:
 * Renders a realistic physical NAS device enclosure showing:
 * - Device frame/bezel in realistic hardware style
 * - Bay slots that look like actual hardware bays
 * - Status indicators with colored overlays
 * - Professional hardware illustration
 *
 * DESIGN:
 * - Teal/dark green enclosure frame
 * - Rectangular bay slots with borders
 * - Color-coded status (green healthy, yellow warning, red critical, gray empty)
 * - Clean, professional appearance matching real hardware
 *
 * @package App\DeepDive\Visualization
 */
final class BayLayoutRenderer
{
    /**
     * Render physical NAS enclosure with bay slots
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

        // Enclosure dimensions
        $width = 500;
        $height = 100 + ($rows * 70);

        $svg = "<svg viewBox=\"0 0 $width $height\" xmlns=\"http://www.w3.org/2000/svg\" class=\"hardware-enclosure\" style=\"max-width:100%;height:auto;\">\n";
        $svg .= "<defs>\n";
        $svg .= "<linearGradient id=\"enclosure-gradient\" x1=\"0%\" y1=\"0%\" x2=\"0%\" y2=\"100%\">\n";
        $svg .= "  <stop offset=\"0%\" style=\"stop-color:#1f2937;stop-opacity:1\" />\n";
        $svg .= "  <stop offset=\"100%\" style=\"stop-color:#111827;stop-opacity:1\" />\n";
        $svg .= "</linearGradient>\n";
        $svg .= "<linearGradient id=\"bay-healthy\" x1=\"0%\" y1=\"0%\" x2=\"0%\" y2=\"100%\">\n";
        $svg .= "  <stop offset=\"0%\" style=\"stop-color:#10b981;stop-opacity:1\" />\n";
        $svg .= "  <stop offset=\"100%\" style=\"stop-color:#059669;stop-opacity:1\" />\n";
        $svg .= "</linearGradient>\n";
        $svg .= "<linearGradient id=\"bay-warning\" x1=\"0%\" y1=\"0%\" x2=\"0%\" y2=\"100%\">\n";
        $svg .= "  <stop offset=\"0%\" style=\"stop-color:#f59e0b;stop-opacity:1\" />\n";
        $svg .= "  <stop offset=\"100%\" style=\"stop-color:#d97706;stop-opacity:1\" />\n";
        $svg .= "</linearGradient>\n";
        $svg .= "<linearGradient id=\"bay-critical\" x1=\"0%\" y1=\"0%\" x2=\"0%\" y2=\"100%\">\n";
        $svg .= "  <stop offset=\"0%\" style=\"stop-color:#dc2626;stop-opacity:1\" />\n";
        $svg .= "  <stop offset=\"100%\" style=\"stop-color:#b91c1c;stop-opacity:1\" />\n";
        $svg .= "</linearGradient>\n";
        $svg .= "</defs>\n";
        $svg .= "<style>\n";
        $svg .= $this->getStyles();
        $svg .= "</style>\n";

        // Title
        $svg .= "<text x=\"20\" y=\"28\" class=\"enclosure-title\">{$containerName}</text>\n";
        $svg .= "<text x=\"20\" y=\"45\" class=\"enclosure-subtitle\">{$totalBays} Bays · " . count($drives) . " installed</text>\n";

        // Enclosure frame (bezel)
        $frameX = 15;
        $frameY = 55;
        $frameWidth = $width - 30;
        $frameHeight = ($rows * 70) + 20;

        $svg .= "<g class=\"enclosure-frame\">\n";
        // Outer bezel
        $svg .= "  <rect x=\"$frameX\" y=\"$frameY\" width=\"$frameWidth\" height=\"$frameHeight\" fill=\"url(#enclosure-gradient)\" rx=\"4\" />\n";
        // Inner panel
        $svg .= "  <rect x=\"" . ($frameX + 8) . "\" y=\"" . ($frameY + 8) . "\" width=\"" . ($frameWidth - 16) . "\" height=\"" . ($frameHeight - 16) . "\" fill=\"#0f172a\" rx=\"2\" />\n";
        $svg .= "</g>\n";

        // Create drive mapping
        $driveMap = [];
        foreach ($drives as $drive) {
            $driveMap[$drive['bay']] = $drive;
        }

        // Render bay slots
        $bayStartX = $frameX + 20;
        $bayStartY = $frameY + 16;
        $bayWidth = 42;
        $bayHeight = 50;
        $bayGapX = 8;
        $bayGapY = 8;

        for ($bay = 1; $bay <= $totalBays; $bay++) {
            $row = (int)floor(($bay - 1) / $columnsPerRow);
            $col = ($bay - 1) % $columnsPerRow;

            $x = $bayStartX + ($col * ($bayWidth + $bayGapX));
            $y = $bayStartY + ($row * ($bayHeight + $bayGapY));

            if (isset($driveMap[$bay])) {
                $svg .= $this->renderBaySlot($driveMap[$bay], $x, $y, $bayWidth, $bayHeight);
            } else {
                $svg .= $this->renderEmptyBay($bay, $x, $y, $bayWidth, $bayHeight);
            }
        }

        // Legend
        $svg .= $this->renderLegend($frameX + 20, $frameY + $frameHeight + 12);

        $svg .= "</svg>\n";

        return $svg;
    }

    /**
     * Calculate optimal columns
     */
    private function getOptimalColumns(int $totalBays): int
    {
        if ($totalBays <= 4) return 4;
        if ($totalBays <= 8) return 4;
        if ($totalBays <= 12) return 4;
        return 5;
    }

    /**
     * Render occupied bay slot
     */
    private function renderBaySlot(array $drive, float $x, float $y, float $w, float $h): string
    {
        $bay = (int)($drive['bay'] ?? 0);
        $serial = htmlspecialchars((string)($drive['serial'] ?? ''), ENT_QUOTES, 'UTF-8');
        $healthStatus = (string)($drive['health_status'] ?? 'unknown');
        $badSectors = (int)($drive['bad_sectors'] ?? 0);

        $status = $this->getStatus($healthStatus, $badSectors);
        $gradient = match($status) {
            'healthy' => 'url(#bay-healthy)',
            'warning' => 'url(#bay-warning)',
            'critical' => 'url(#bay-critical)',
            default => '#e5e7eb',
        };
        $icon = $this->getIcon($status);

        $svg = "<g class=\"bay-slot\" data-bay=\"$bay\">\n";

        // Bay slot rectangle with gradient
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" fill=\"$gradient\" stroke=\"#1f2937\" stroke-width=\"1\" rx=\"3\" />\n";

        // Bay number (large, centered)
        $svg .= "  <text x=\"" . ($x + $w / 2) . "\" y=\"" . ($y + 20) . "\" class=\"bay-number\" text-anchor=\"middle\">$bay</text>\n";

        // Status icon (top-right)
        $svg .= "  <text x=\"" . ($x + $w - 6) . "\" y=\"" . ($y + 10) . "\" class=\"bay-status-icon\" text-anchor=\"end\">$icon</text>\n";

        // Serial (bottom, truncated)
        $shortSerial = strlen($serial) > 8 ? substr($serial, 0, 8) : $serial;
        $svg .= "  <text x=\"" . ($x + 2) . "\" y=\"" . ($y + $h - 3) . "\" class=\"bay-serial\">$shortSerial</text>\n";

        // Tooltip
        $tooltip = "Bay $bay\n{$serial}\n" . htmlspecialchars((string)($drive['model'] ?? ''), ENT_QUOTES, 'UTF-8');
        if ($badSectors > 0) {
            $tooltip .= "\nBad Sectors: $badSectors";
        }
        $svg .= "  <title>$tooltip</title>\n";

        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render empty bay
     */
    private function renderEmptyBay(int $bay, float $x, float $y, float $w, float $h): string
    {
        $svg = "<g class=\"bay-slot empty\" data-bay=\"$bay\">\n";
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" fill=\"#374151\" stroke=\"#4b5563\" stroke-width=\"1\" stroke-dasharray=\"3,2\" rx=\"3\" />\n";
        $svg .= "  <text x=\"" . ($x + $w / 2) . "\" y=\"" . ($y + $h / 2 + 5) . "\" class=\"bay-number-empty\" text-anchor=\"middle\">$bay</text>\n";
        $svg .= "  <title>Bay $bay - Empty</title>\n";
        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render legend
     */
    private function renderLegend(float $x, float $y): string
    {
        return <<<SVG
<g class="legend">
  <text x="$x" y="$y" class="legend-title">Status Legend</text>
  <circle cx="{$x}" cy="{$y + 18}" r="5" fill="#10b981" />
  <text x="{$x + 12}" y="{$y + 22}" class="legend-text">Healthy</text>

  <circle cx="{$x + 90}" cy="{$y + 18}" r="5" fill="#f59e0b" />
  <text x="{$x + 102}" y="{$y + 22}" class="legend-text">Warning</text>

  <circle cx="{$x + 190}" cy="{$y + 18}" r="5" fill="#dc2626" />
  <text x="{$x + 202}" y="{$y + 22}" class="legend-text">Critical</text>

  <circle cx="{$x + 280}" cy="{$y + 18}" r="5" fill="#6b7280" />
  <text x="{$x + 292}" y="{$y + 22}" class="legend-text">Empty</text>
</g>
SVG;
    }

    /**
     * Determine status
     */
    private function getStatus(string $status, int $badSectors): string
    {
        if ($status === 'critical' || $badSectors > 100) {
            return 'critical';
        } elseif ($status === 'warning' || $badSectors > 50) {
            return 'warning';
        } elseif ($status === 'caution' || $badSectors > 10) {
            return 'caution';
        }
        return 'healthy';
    }

    /**
     * Get status icon
     */
    private function getIcon(string $status): string
    {
        return match($status) {
            'healthy' => '✓',
            'caution' => '!',
            'warning' => '⚠',
            'critical' => '✕',
            default => '○',
        };
    }

    /**
     * Get CSS styles
     */
    private function getStyles(): string
    {
        return <<<'CSS'
.hardware-enclosure {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
}

.enclosure-title {
    font-size: 16px;
    font-weight: 700;
    fill: #1f2937;
    letter-spacing: -0.3px;
}

.enclosure-subtitle {
    font-size: 12px;
    fill: #6b7280;
    font-weight: 500;
}

.enclosure-frame {
    filter: drop-shadow(0 2px 4px rgba(0, 0, 0, 0.1));
}

.bay-slot {
    cursor: pointer;
    transition: opacity 0.2s ease;
}

.bay-slot:hover rect {
    opacity: 0.9;
    filter: drop-shadow(0 2px 8px rgba(0, 0, 0, 0.2));
}

.bay-number {
    font-size: 18px;
    font-weight: 700;
    fill: white;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.bay-number-empty {
    font-size: 16px;
    fill: #9ca3af;
    font-weight: 600;
}

.bay-serial {
    font-size: 8px;
    fill: white;
    font-family: 'Monaco', 'Courier New', monospace;
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.bay-status-icon {
    font-size: 12px;
    fill: white;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
}

.legend-title {
    font-size: 11px;
    font-weight: 700;
    fill: #1f2937;
    letter-spacing: 0.05em;
    text-transform: uppercase;
}

.legend-text {
    font-size: 10px;
    fill: #6b7280;
    font-weight: 500;
}

CSS;
    }
}
