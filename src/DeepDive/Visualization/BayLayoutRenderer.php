<?php

declare(strict_types=1);

namespace App\DeepDive\Visualization;

/**
 * BayLayoutRenderer: Modernized drive bay layout visualization
 *
 * PURPOSE:
 * Generates modern SVG diagrams of drive bay layout with:
 * - Responsive grid layout (adapts to screen width)
 * - Contemporary visual design (gradients, shadows, modern colors)
 * - Rich interactive tooltips with detailed drive information
 * - Enhanced health status indicators and replacement tracking
 *
 * VISUALIZATION FEATURES:
 * - Modern color palette with gradient backgrounds
 * - Soft shadows and depth effects for visual hierarchy
 * - Responsive grid (auto-adjust columns based on total bays)
 * - Enhanced tooltips showing full drive details
 * - Visual replacement history indicators
 * - Problem slot highlighting (3+ replacements)
 * - Health status badges with modern icons
 *
 * HEALTH INDICATORS:
 * - Healthy (Green): Operational, 0 bad sectors
 * - Caution (Amber): Minor issues, 10-50 bad sectors
 * - Warning (Orange): Moderate issues, 50+ bad sectors
 * - Critical (Red): Failed or 100+ bad sectors
 *
 * INTERACTIVE ELEMENTS:
 * - Hover: Lift effect with shadow, text emphasis
 * - Tooltips: Comprehensive drive info on hover
 * - Color-coded replacement history border
 * - Problem slot indicator (3+ replacements)
 *
 * @package App\DeepDive\Visualization
 */
final class BayLayoutRenderer
{
    /**
     * Render complete bay layout diagram with modern design
     *
     * @param string $containerName Display name (main unit, expansion, etc.)
     * @param array $drives List of installed drives with metadata
     * @param int $totalBays Total bay count for container
     * @param int $columnsPerRow Grid columns (auto-calculated if 0)
     *
     * @return string SVG document string ready for embedding
     */
    public function renderBayLayout(
        string $containerName,
        array $drives,
        int $totalBays = 12,
        int $columnsPerRow = 0
    ): string {
        $containerName = htmlspecialchars($containerName, ENT_QUOTES, 'UTF-8');

        // Auto-calculate columns based on bay count for responsiveness
        if ($columnsPerRow <= 0) {
            $columnsPerRow = $this->getResponsiveColumns($totalBays);
        }

        $rows = (int)ceil($totalBays / $columnsPerRow);
        $width = 450;
        $height = 100 + ($rows * 85); // Increased spacing for modern look

        $svg = "<svg viewBox=\"0 0 $width $height\" xmlns=\"http://www.w3.org/2000/svg\" class=\"bay-layout\" style=\"max-width:100%;height:auto;\">\n";
        $svg .= "<defs>\n";
        $svg .= $this->getGradientDefinitions();
        $svg .= $this->getFilterDefinitions();
        $svg .= "</defs>\n";
        $svg .= "<style>\n";
        $svg .= $this->getModernStyles();
        $svg .= "</style>\n";

        // Background
        $svg .= "<rect width=\"$width\" height=\"$height\" fill=\"url(#bg-gradient)\" />\n";

        // Title section with modern styling
        $svg .= "<g class=\"bay-header\">\n";
        $svg .= "  <text x=\"25\" y=\"35\" class=\"bay-title\">{$containerName}</text>\n";
        $svg .= "  <text x=\"25\" y=\"55\" class=\"bay-subtitle\">{$totalBays} Bays · " . count($drives) . " occupied</text>\n";
        $svg .= "</g>\n";

        // Create drive mapping
        $driveMap = [];
        foreach ($drives as $drive) {
            $driveMap[$drive['bay']] = $drive;
        }

        // Render bays with modern spacing
        $xStart = 25;
        $yStart = 75;
        $bayWidth = 95;
        $bayHeight = 75;
        $xGap = 15;
        $yGap = 20;

        $svg .= "<g class=\"bays-grid\">\n";
        for ($bay = 1; $bay <= $totalBays; $bay++) {
            $row = (int)floor(($bay - 1) / $columnsPerRow);
            $col = ($bay - 1) % $columnsPerRow;

            $x = $xStart + ($col * ($bayWidth + $xGap));
            $y = $yStart + ($row * ($bayHeight + $yGap));

            if (isset($driveMap[$bay])) {
                $svg .= $this->renderBayModern($driveMap[$bay], $x, $y, $bayWidth, $bayHeight);
            } else {
                $svg .= $this->renderEmptyBayModern($bay, $x, $y, $bayWidth, $bayHeight);
            }
        }
        $svg .= "</g>\n";

        // Modern legend section
        $svg .= $this->renderLegendModern();

        $svg .= "</svg>\n";

        return $svg;
    }

    /**
     * Auto-calculate responsive column count based on bay count
     */
    private function getResponsiveColumns(int $totalBays): int
    {
        if ($totalBays <= 4) return 2;
        if ($totalBays <= 6) return 3;
        if ($totalBays <= 12) return 4;
        if ($totalBays <= 16) return 4;
        return 5;
    }

    /**
     * Render modern occupied bay with enhanced styling
     */
    private function renderBayModern(array $drive, float $x, float $y, float $w, float $h): string
    {
        $bay = (int)($drive['bay'] ?? 0);
        $serial = htmlspecialchars((string)($drive['serial'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8');
        $model = htmlspecialchars((string)($drive['model'] ?? ''), ENT_QUOTES, 'UTF-8');
        $healthStatus = (string)($drive['health_status'] ?? 'unknown');
        $badSectors = (int)($drive['bad_sectors'] ?? 0);
        $installed = (string)($drive['installation_date'] ?? 'Unknown');
        $replacementCount = (int)($drive['replacement_count'] ?? 0);
        $isSsd = (bool)($drive['is_ssd'] ?? false);
        $capacity = (float)($drive['capacity_gb'] ?? 0);

        $colorClass = $this->getHealthColorClass($healthStatus, $badSectors);
        $icon = $this->getHealthIcon($healthStatus, $badSectors);

        // Replacement indicators
        $borderStroke = '';
        if ($replacementCount >= 3) {
            $borderStroke = " stroke=\"#dc2626\" stroke-width=\"2.5\" filter=\"url(#problem-shadow)\"";
        } elseif ($replacementCount > 0 && $this->isRecentlyReplaced($installed)) {
            $borderStroke = " stroke=\"#f59e0b\" stroke-width=\"2\"";
        }

        $svg = "<g class=\"bay-item\" data-bay=\"$bay\" data-health=\"$healthStatus\">\n";

        // Main background with shadow
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" class=\"bay-bg $colorClass\" rx=\"8\" ry=\"8\" filter=\"url(#bay-shadow)\"{$borderStroke} />\n";

        // Bay number badge (top-left)
        $svg .= "  <rect x=\"" . ($x + 6) . "\" y=\"" . ($y + 6) . "\" width=\"22\" height=\"20\" rx=\"4\" class=\"bay-number-bg\" />\n";
        $svg .= "  <text x=\"" . ($x + 17) . "\" y=\"" . ($y + 18) . "\" class=\"bay-number\" text-anchor=\"middle\">{$bay}</text>\n";

        // Health icon (top-right)
        $svg .= "  <text x=\"" . ($x + $w - 10) . "\" y=\"" . ($y + 18) . "\" class=\"health-icon\" text-anchor=\"end\">{$icon}</text>\n";

        // Drive type indicator
        $typeLabel = $isSsd ? 'SSD' : 'HDD';
        $svg .= "  <text x=\"" . ($x + 6) . "\" y=\"" . ($y + 40) . "\" class=\"bay-type\">{$typeLabel}</text>\n";

        // Serial (middle - truncated for space)
        $shortSerial = strlen($serial) > 11 ? substr($serial, 0, 11) : $serial;
        $svg .= "  <text x=\"" . ($x + 6) . "\" y=\"" . ($y + 52) . "\" class=\"bay-serial\">{$shortSerial}</text>\n";

        // Model (smaller text)
        $shortModel = strlen($model) > 10 ? substr($model, 0, 9) . '…' : $model;
        $svg .= "  <text x=\"" . ($x + 6) . "\" y=\"" . ($y + 62) . "\" class=\"bay-model\">{$shortModel}</text>\n";

        // Bad sectors indicator if present
        if ($badSectors > 0) {
            $badSectorLabel = $badSectors > 99 ? '99+' : (string)$badSectors;
            $svg .= "  <circle cx=\"" . ($x + $w - 12) . "\" cy=\"" . ($y + $h - 10) . "\" r=\"8\" class=\"bay-sector-badge\" />\n";
            $svg .= "  <text x=\"" . ($x + $w - 12) . "\" y=\"" . ($y + $h - 6) . "\" class=\"bay-sector-count\" text-anchor=\"middle\">{$badSectorLabel}</text>\n";
        }

        // Replacement count indicator if applicable
        if ($replacementCount > 0) {
            $svg .= "  <text x=\"" . ($x + 6) . "\" y=\"" . ($y + $h - 4) . "\" class=\"replacement-badge\">↻ {$replacementCount}</text>\n";
        }

        // Rich tooltip with detailed information
        $tooltipText = "Slot {$bay}\n{$serial}\n{$model} ({$capacity}GB)\n";
        $tooltipText .= "Status: " . ucfirst($healthStatus) . "\n";
        $tooltipText .= "Installed: {$installed}\n";
        if ($replacementCount > 0) {
            $tooltipText .= "Replaced: {$replacementCount}x\n";
        }
        if ($badSectors > 0) {
            $tooltipText .= "Bad Sectors: {$badSectors}\n";
        }
        $tooltipText .= "Type: {$typeLabel}";

        $svg .= "  <title>{$tooltipText}</title>\n";
        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render modern empty bay
     */
    private function renderEmptyBayModern(int $bay, float $x, float $y, float $w, float $h): string
    {
        $svg = "<g class=\"bay-item empty\" data-bay=\"$bay\">\n";
        $svg .= "  <rect x=\"$x\" y=\"$y\" width=\"$w\" height=\"$h\" class=\"bay-bg bay-empty\" rx=\"8\" ry=\"8\" filter=\"url(#bay-shadow)\" />\n";

        // Bay number
        $svg .= "  <rect x=\"" . ($x + 6) . "\" y=\"" . ($y + 6) . "\" width=\"22\" height=\"20\" rx=\"4\" class=\"bay-number-bg-empty\" />\n";
        $svg .= "  <text x=\"" . ($x + 17) . "\" y=\"" . ($y + 18) . "\" class=\"bay-number-empty\" text-anchor=\"middle\">{$bay}</text>\n";

        // Empty indicator
        $svg .= "  <text x=\"" . ($x + $w / 2) . "\" y=\"" . ($y + $h / 2 + 5) . "\" class=\"bay-empty-text\" text-anchor=\"middle\">Empty</text>\n";
        $svg .= "  <title>Bay {$bay} - Empty</title>\n";
        $svg .= "</g>\n";

        return $svg;
    }

    /**
     * Render modern legend section
     */
    private function renderLegendModern(): string
    {
        return <<<'SVG'
<g class="legend" transform="translate(25, 0)">
  <text x="0" y="-10" class="legend-title">Health Status</text>
  <g transform="translate(0, 0)">
    <rect x="0" y="0" width="14" height="14" rx="2" fill="url(#grad-healthy)" />
    <text x="20" y="12" class="legend-item">Healthy</text>
  </g>
  <g transform="translate(120, 0)">
    <rect x="0" y="0" width="14" height="14" rx="2" fill="url(#grad-caution)" />
    <text x="20" y="12" class="legend-item">Caution</text>
  </g>
  <g transform="translate(240, 0)">
    <rect x="0" y="0" width="14" height="14" rx="2" fill="url(#grad-warning)" />
    <text x="20" y="12" class="legend-item">Warning</text>
  </g>
  <g transform="translate(350, 0)">
    <rect x="0" y="0" width="14" height="14" rx="2" fill="url(#grad-critical)" />
    <text x="20" y="12" class="legend-item">Critical</text>
  </g>
</g>
SVG;
    }

    /**
     * SVG gradient definitions for modern look
     */
    private function getGradientDefinitions(): string
    {
        return <<<'SVG'
<linearGradient id="bg-gradient" x1="0%" y1="0%" x2="100%" y2="100%">
  <stop offset="0%" style="stop-color:#f8fafc;stop-opacity:1" />
  <stop offset="100%" style="stop-color:#f1f5f9;stop-opacity:1" />
</linearGradient>
<linearGradient id="grad-healthy" x1="0%" y1="0%" x2="0%" y2="100%">
  <stop offset="0%" style="stop-color:#10b981;stop-opacity:1" />
  <stop offset="100%" style="stop-color:#059669;stop-opacity:1" />
</linearGradient>
<linearGradient id="grad-caution" x1="0%" y1="0%" x2="0%" y2="100%">
  <stop offset="0%" style="stop-color:#fcd34d;stop-opacity:1" />
  <stop offset="100%" style="stop-color:#f59e0b;stop-opacity:1" />
</linearGradient>
<linearGradient id="grad-warning" x1="0%" y1="0%" x2="0%" y2="100%">
  <stop offset="0%" style="stop-color:#fb923c;stop-opacity:1" />
  <stop offset="100%" style="stop-color:#ea580c;stop-opacity:1" />
</linearGradient>
<linearGradient id="grad-critical" x1="0%" y1="0%" x2="0%" y2="100%">
  <stop offset="0%" style="stop-color:#f87171;stop-opacity:1" />
  <stop offset="100%" style="stop-color:#dc2626;stop-opacity:1" />
</linearGradient>
SVG;
    }

    /**
     * SVG filter definitions for shadows and effects
     */
    private function getFilterDefinitions(): string
    {
        return <<<'SVG'
<filter id="bay-shadow" x="-50%" y="-50%" width="200%" height="200%">
  <feDropShadow dx="0" dy="2" stdDeviation="3" flood-opacity="0.12" flood-color="#000" />
</filter>
<filter id="problem-shadow" x="-50%" y="-50%" width="200%" height="200%">
  <feDropShadow dx="0" dy="2" stdDeviation="4" flood-opacity="0.2" flood-color="#dc2626" />
</filter>
SVG;
    }

    /**
     * Get modern CSS styles
     */
    private function getModernStyles(): string
    {
        return <<<'CSS'
.bay-layout {
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
}

.bay-header {
    pointer-events: none;
}

.bay-title {
    font-size: 18px;
    font-weight: 700;
    fill: #1e293b;
    letter-spacing: -0.3px;
}

.bay-subtitle {
    font-size: 12px;
    fill: #64748b;
    font-weight: 500;
    letter-spacing: -0.1px;
}

.bays-grid {
    pointer-events: auto;
}

.bay-item {
    cursor: pointer;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

.bay-item:hover {
    transform: translateY(-2px);
}

.bay-item:hover .bay-bg {
    opacity: 0.95;
    filter: drop-shadow(0 6px 16px rgba(0, 0, 0, 0.15)) !important;
}

.bay-item:hover .bay-number,
.bay-item:hover .bay-serial,
.bay-item:hover .bay-model {
    font-weight: 700;
}

.bay-bg {
    stroke-width: 1.5;
    transition: all 0.3s ease;
}

.bay-healthy {
    fill: url(#grad-healthy);
    stroke: #059669;
}

.bay-caution {
    fill: url(#grad-caution);
    stroke: #d97706;
}

.bay-warning {
    fill: url(#grad-warning);
    stroke: #c2410c;
}

.bay-critical {
    fill: url(#grad-critical);
    stroke: #991b1b;
}

.bay-empty {
    fill: #e2e8f0;
    stroke: #cbd5e1;
    stroke-dasharray: 4,3;
}

.bay-number-bg {
    fill: rgba(255, 255, 255, 0.25);
    stroke: rgba(255, 255, 255, 0.4);
    stroke-width: 0.5;
}

.bay-number-bg-empty {
    fill: rgba(0, 0, 0, 0.05);
    stroke: rgba(0, 0, 0, 0.1);
    stroke-width: 0.5;
}

.bay-number {
    font-size: 11px;
    font-weight: 700;
    fill: white;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.bay-number-empty {
    font-size: 11px;
    font-weight: 700;
    fill: #64748b;
    text-shadow: 0 1px 1px rgba(255, 255, 255, 0.5);
}

.bay-type {
    font-size: 8px;
    fill: white;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.1);
    opacity: 0.85;
}

.bay-serial {
    font-size: 9px;
    fill: white;
    font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Roboto Mono', monospace;
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
    letter-spacing: -0.2px;
}

.bay-model {
    font-size: 8px;
    fill: white;
    font-weight: 500;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.15);
}

.bay-sector-badge {
    fill: rgba(0, 0, 0, 0.2);
    stroke: rgba(255, 255, 255, 0.3);
    stroke-width: 0.5;
}

.bay-sector-count {
    font-size: 10px;
    fill: white;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.replacement-badge {
    font-size: 8px;
    fill: white;
    font-weight: 700;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.2);
}

.bay-empty-text {
    font-size: 13px;
    fill: #94a3b8;
    font-weight: 600;
    letter-spacing: -0.2px;
}

.health-icon {
    font-size: 16px;
    fill: white;
    text-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
    font-weight: 700;
}

.legend {
    font-size: 11px;
    fill: #475569;
    font-weight: 500;
}

.legend-title {
    font-size: 11px;
    font-weight: 700;
    fill: #1e293b;
    letter-spacing: -0.2px;
}

.legend-item {
    font-size: 10px;
    fill: #475569;
    font-weight: 500;
}

CSS;
    }

    /**
     * Get health color class
     */
    private function getHealthColorClass(string $status, int $badSectors): string
    {
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
     * Get modern health icon
     */
    private function getHealthIcon(string $status, int $badSectors): string
    {
        if ($status === 'critical' || $badSectors > 100) {
            return '✕';
        } elseif ($status === 'warning' || $badSectors > 50) {
            return '⚠';
        } elseif ($status === 'caution' || $badSectors > 10) {
            return '!';
        }
        return '✓';
    }

    /**
     * Check if drive was installed recently (< 6 months)
     */
    private function isRecentlyReplaced(string $dateStr): bool
    {
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
}
