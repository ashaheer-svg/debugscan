<?php

declare(strict_types=1);

namespace App\DeepDive\Logging;

/**
 * Version Registry: Central registry for component versions
 *
 * PURPOSE:
 * Maintains a centralized registry of all key component versions
 * to verify that code updates have been deployed correctly.
 *
 * USAGE:
 * VersionRegistry::register('PowerSupplyParser', '3.1.0');
 * VersionRegistry::all(); // Get all registered versions
 *
 * @package App\DeepDive\Logging
 */
class VersionRegistry
{
    /**
     * Core component versions
     * Update these when deploying new versions
     */
    private static array $versions = [
        // === Pipeline Components ===
        'PipelineLogger' => '1.0.0',
        'VersionRegistry' => '1.0.0',
        'LogExporter' => '1.0.0',

        // === Parser Components ===
        'PowerSupplyParser' => '3.1.0',
        'HardwareSpecExtractor' => '1.5.0',
        'BundleLocator' => '1.0.0',

        // === Validation Components ===
        'FileAvailabilityValidator' => '1.0.0',

        // === Pipeline Steps ===
        'DecompressStep' => '1.0.0',
        'ParseStep' => '2.1.0',
        'EvaluateStep' => '1.0.0',
        'CorrelateStep' => '1.0.0',
        'RenderStep' => '3.1.0',
        'RenderStepFixed' => '3.1.0',

        // === AI Analysis Components ===
        'AnomalyDetector' => '1.0.0',
        'EventCorrelator' => '1.0.0',
        'RootCauseAnalyzer' => '1.0.0',
        'LogPreprocessor' => '1.0.0',

        // === Core Application ===
        'DeepDive' => '3.1.0',
    ];

    /**
     * Register a component version
     *
     * @param string $component Component name
     * @param string $version Version string (semver recommended)
     *
     * @return void
     */
    public static function register(string $component, string $version): void
    {
        self::$versions[$component] = $version;
    }

    /**
     * Get version of specific component
     *
     * @param string $component Component name
     * @param string $default Default value if not found
     *
     * @return string Version string
     */
    public static function get(string $component, string $default = 'unknown'): string
    {
        return self::$versions[$component] ?? $default;
    }

    /**
     * Get all registered versions
     *
     * @return array<string, string> Map of component => version
     */
    public static function all(): array
    {
        return self::$versions;
    }

    /**
     * Verify minimum versions
     *
     * Useful for checking if all required components are updated
     *
     * @param array<string, string> $required Map of component => minimum_version
     *
     * @return array{success: bool, missing: array, outdated: array}
     */
    public static function verify(array $required): array
    {
        $missing = [];
        $outdated = [];

        foreach ($required as $component => $minVersion) {
            if (!isset(self::$versions[$component])) {
                $missing[$component] = $minVersion;
                continue;
            }

            if (version_compare(self::$versions[$component], $minVersion, '<')) {
                $outdated[$component] = [
                    'required' => $minVersion,
                    'current' => self::$versions[$component],
                ];
            }
        }

        return [
            'success' => empty($missing) && empty($outdated),
            'missing' => $missing,
            'outdated' => $outdated,
        ];
    }

    /**
     * Get version summary as string
     *
     * Useful for logging and debugging
     *
     * @return string Formatted version summary
     */
    public static function summary(): string
    {
        $lines = [];
        $lines[] = '=== Component Versions ===';

        // Group by category
        $categories = [
            'Pipeline' => ['PipelineLogger', 'VersionRegistry', 'LogExporter'],
            'Parsers' => ['PowerSupplyParser', 'HardwareSpecExtractor', 'BundleLocator'],
            'Validation' => ['FileAvailabilityValidator'],
            'Steps' => ['DecompressStep', 'ParseStep', 'EvaluateStep', 'CorrelateStep', 'RenderStep', 'RenderStepFixed'],
            'AI' => ['AnomalyDetector', 'EventCorrelator', 'RootCauseAnalyzer', 'LogPreprocessor'],
            'Core' => ['DeepDive'],
        ];

        foreach ($categories as $category => $components) {
            $lines[] = "\n{$category}:";
            foreach ($components as $component) {
                $version = self::get($component);
                $lines[] = "  {$component}: {$version}";
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get version HTML table
     *
     * @return string HTML table of versions
     */
    public static function toHtml(): string
    {
        $html = '<table style="border-collapse: collapse; width: 100%; margin: 10px 0;">';
        $html .= '<tr style="background: #f5f5f5;"><th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Component</th><th style="padding: 10px; text-align: left; border: 1px solid #ddd;">Version</th></tr>';

        foreach (self::$versions as $component => $version) {
            $html .= '<tr>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd;"><strong>' . htmlspecialchars($component) . '</strong></td>';
            $html .= '<td style="padding: 8px; border: 1px solid #ddd;"><code>' . htmlspecialchars($version) . '</code></td>';
            $html .= '</tr>';
        }

        $html .= '</table>';
        return $html;
    }
}
