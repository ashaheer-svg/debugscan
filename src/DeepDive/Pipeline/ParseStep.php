<?php

declare(strict_types=1);

namespace App\DeepDive\Pipeline;

use App\DeepDive\Hardware\HardwareSpecExtractor;
use App\Parsers\PowerSupplyParser;
use App\DeepDive\Parsers\BundleLocator;
use App\DeepDive\Parsers\TimestampParser;
use App\DeepDive\Parsers\FileAvailabilityValidator;
use App\DeepDive\Rules\Sources\SourceRegistry;

/**
 * Parse Step: Locate data sources and extract hardware specifications
 *
 * PURPOSE:
 * Walks each decompressed debug bundle, identifies known DSM logical sources
 * (system logs, kernel logs, sqlite databases, etc.) and registers them into
 * a unified SourceRegistry for rules to query. Also extracts hardware specs.
 *
 * SEQUENCE:
 * Executes after DecompressStep (receives decompressed bundles), before EvaluateStep
 *
 * DATA SOURCE DISCOVERY:
 * BundleLocator scans each bundle directory for known file patterns:
 * - System logs: /var/log/messages, kern.log, scemd, mdstat
 * - Configuration files: DSM configs, network settings
 * - Database files: sqlite artefacts
 * - Hardware info: /proc files, system info
 *
 * UNIFIED REGISTRY:
 * All bundles contribute to the SAME SourceRegistry:
 * - Enables cross-bundle rule evaluation
 * - Rules can correlate evidence across multiple snapshot files
 * - Mimics how analyst reads multiple files side-by-side
 * - Single registry passed to EvaluateStep
 *
 * HARDWARE EXTRACTION:
 * HardwareSpecExtractor runs on each bundle:
 * - Extracts CPU, RAM, drives, RAID config, etc.
 * - Calculates completeness score
 * - Stores in bundle metadata for report
 *
 * BUNDLE FACTS:
 * For each bundle, generates metadata for report:
 * - File count, total size, top-level directory listing
 * - List of data sources found
 * - Used by report for "bundles processed" section
 *
 * @package App\DeepDive\Pipeline
 */
final class ParseStep implements StepInterface
{
    /**
     * Get step identifier
     *
     * @return string 'parse'
     */
    public function id(): string
    {
        return 'parse';
    }

    /**
     * Parse bundles and build unified source registry
     *
     * FLOW:
     * 1. Initialize BundleLocator (identifies known file patterns)
     * 2. Initialize empty SourceRegistry (will be populated)
     * 3. For each bundle:
     *    a. Locate data sources (logs, databases, etc.)
     *    b. Register all sources into shared registry
     *    c. Extract hardware specifications
     *    d. Extract power supply information
     *    e. Generate metadata facts for report
     * 4. Store complete registry for EvaluateStep
     * 5. Record statistics and complete step
     *
     * UNIFIED REGISTRY:
     * Single SourceRegistry shared across all bundles
     * Allows rules to correlate across multiple snapshots
     *
     * HARDWARE EXTRACTION:
     * HardwareSpecExtractor analyzes each bundle independently:
     * - Calculates data completeness scores
     * - Extracts hardware configuration
     * - Generates missing/extracted fields lists
     *
     * POWER SUPPLY EXTRACTION:
     * PowerSupplyParser analyzes power system health:
     * - Extracts DMI Type 39 (System Power Supply) information
     * - Parses IPMI voltage/current sensors
     * - Extracts IPMI System Event Log for power anomalies
     * - Performs health assessment with risk factors
     *
     * @param PipelineContext $ctx Shared pipeline context
     *
     * @return void Populates $ctx->bag['source_registry'], facts, and power_data
     */
    public function run(PipelineContext $ctx): void
    {
        $ctx->startStep($this->id());

        // Initialize parsers and data structures
        $year      = (int)date('Y'); // Current year for timestamp parsing
        $locator   = new BundleLocator(new TimestampParser($year));
        $registry  = new SourceRegistry(); // Unified registry for all bundles
        $facts     = [];                    // Per-bundle metadata for report
        $hwExtractor = new HardwareSpecExtractor();
        $powerParser = new PowerSupplyParser();   // Power supply analysis
        $fileValidator = new FileAvailabilityValidator(); // File availability check
        $powerData = [];                          // Collected power data

        // === Process each bundle ===
        foreach ($ctx->bag['bundles'] as &$bundle) {
            // Get extracted bundle directory
            $base = $bundle['extracted_path'] ?? null;
            if (!$base || !is_dir($base)) continue;

            // === Locate and register data sources ===
            // BundleLocator identifies known file patterns (logs, sqlite, etc.)
            $bundleReg = $locator->locate($base);

            // Add all found logs to unified registry
            foreach ($bundleReg->allLogs() as $src) {
                $registry->registerLog($src);
            }

            // Add all found sqlite databases to unified registry
            foreach ($bundleReg->allSqlite() as $src) {
                $registry->registerSqlite($src);
            }

            // === Validate file availability ===
            // Pre-flight check: ensure required data files exist before parsing
            $fileManifest = $fileValidator->validateBundle($base);

            // === Extract hardware specifications ===
            // Analyzes system information in bundle
            $hardwareSpec = $hwExtractor->extract($base);
            $bundle['hardware_spec'] = $hardwareSpec;

            // Store completeness metrics in bundle metadata
            $bundle['data_completeness'] = [
                'hardware_score'      => $hardwareSpec->completenessScore(),
                'hardware_assessment' => $hardwareSpec->completenessAssessment(),
                'extracted_fields'    => $hardwareSpec->extractedFields(),
                'missing_fields'      => $hardwareSpec->missingFields(),
            ];

            // === Extract power supply information ===
            // Analyzes power system health (DMI, IPMI, events)
            // Only parse power data if required files are available or alternatives found
            try {
                $psuResult = $powerParser->parse($base, [
                    'hardware' => $hardwareSpec,
                    'majorversion' => 7  // DSM version for context
                ]);

                // Collect power data for later rendering
                $powerData[] = [
                    'bundle_id' => $bundle['debug_file_id'] ?? basename($base),
                    'bundle_name' => basename($base),
                    'data' => $psuResult['data'] ?? [],
                    'citations' => $psuResult['citations'] ?? [],
                ];

                // Store in bundle for access during rendering
                $bundle['power_data'] = $psuResult['data'] ?? [];
            } catch (\Throwable $e) {
                // Log error but continue - power data is supplementary
                error_log("PowerSupplyParser error for {$base}: " . $e->getMessage());
                $bundle['power_data'] = null;
            }

            // === Generate per-bundle facts for report ===
            $facts[] = [
                'debug_file_id'  => $bundle['debug_file_id'],                              // For tracking
                'root'           => basename($base),                                        // Bundle directory name
                'file_count'     => $this->countFiles($base),                               // Total files in bundle
                'size_bytes'     => $this->dirSize($base),                                  // Total size
                'top_level'      => $this->topLevel($base),                                 // First 20 items in root
                'sources_log'    => array_keys($bundleReg->allLogs()),                      // Log files found
                'sources_sqlite' => array_keys($bundleReg->allSqlite()),                    // Databases found
                'file_availability' => [                                                   // Pre-flight validation results
                    'completeness_pct' => $fileManifest['completeness_pct'],
                    'assessment' => $fileManifest['assessment'],
                    'critical_available' => $fileManifest['critical_available'],
                    'critical_total' => $fileManifest['total_critical'],
                    'power_available' => $fileManifest['power_available'],
                    'power_total' => $fileManifest['total_power'],
                    'issues' => $fileManifest['issues'],
                ],
            ];
        }
        unset($bundle); // Unset reference to avoid side effects

        // Store results in context for downstream steps
        $ctx->bag['facts']           = $facts;
        $ctx->bag['source_registry'] = $registry;
        $ctx->bag['power_data']      = $powerData;  // Power supply information for rendering

        // Report step completion with statistics
        $detail = sprintf(
            '%d bundle(s), %d log source(s), %d sqlite source(s), %d power analysis(s)',
            count($facts),
            count($registry->allLogs()),
            count($registry->allSqlite()),
            count($powerData),
        );
        $ctx->stepDetail($this->id(), $detail);
        $ctx->completeStep($this->id());
    }

    /**
     * Internal helper: Count total files in directory tree
     *
     * ALGORITHM:
     * Recursively iterates directory tree, counts regular files
     * Silently catches permission errors (returns partial count)
     *
     * @param string $dir Directory path
     *
     * @return int Total file count (0 if directory doesn't exist or inaccessible)
     */
    private function countFiles(string $dir): int
    {
        $n = 0;
        try {
            // Recursively iterate all files in directory tree
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                if ($f->isFile()) $n++;
            }
        } catch (\Throwable) {
            // Permission error or other issue: return partial count
        }
        return $n;
    }

    /**
     * Internal helper: Calculate total directory size
     *
     * ALGORITHM:
     * Recursively iterates all files, sums their sizes
     * Silently catches permission errors (returns partial size)
     *
     * @param string $dir Directory path
     *
     * @return int Total size in bytes (0 if directory doesn't exist)
     */
    private function dirSize(string $dir): int
    {
        $n = 0;
        try {
            // Recursively iterate all files in directory tree
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iter as $f) {
                /** @var \SplFileInfo $f */
                if ($f->isFile()) {
                    $n += $f->getSize();
                }
            }
        } catch (\Throwable) {
            // Permission error: return partial size
        }
        return $n;
    }

    /**
     * Internal helper: Get top-level directory listing
     *
     * LIMIT:
     * Returns first 20 items only (for summary display, not exhaustive)
     *
     * @param string $dir Directory path
     *
     * @return array Array of top-level item names (files and subdirs)
     */
    private function topLevel(string $dir): array
    {
        $items = [];
        // Scan directory (safely handles missing directory with ?:)
        foreach (scandir($dir) ?: [] as $name) {
            // Skip . and ..
            if ($name === '.' || $name === '..') continue;
            $items[] = $name;
            // Stop at 20 items (prevents huge listings in reports)
            if (count($items) >= 20) break;
        }
        return $items;
    }
}
