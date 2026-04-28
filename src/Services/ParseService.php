<?php

declare(strict_types=1);

namespace App\Services;

use App\Parsers\ParserInterface;
use App\Parsers\VersionParser;
use App\Parsers\HardwareParser;
use App\Parsers\DiskParser;
use App\Parsers\RaidParser;
use App\Parsers\VolumeParser;
use App\Parsers\NetworkParser;
use App\Parsers\LogParser;
use App\Parsers\BtrfsScrubParser;
use App\Parsers\DStateParser;
use App\Parsers\DfResultParser;
use App\Parsers\DiskstatsParser;
use App\Parsers\TopResultParser;
use App\Parsers\VmstatParser;
use App\Parsers\NetworkHardwareParser;
use RuntimeException;

/**
 * ParseService: Orchestrate parser execution on extracted diagnostic data
 *
 * PURPOSE:
 * Registry and executor for all diagnostic data parsers
 * Coordinates parser order (version before hardware, etc.)
 * Respects extraction config (enable/disable sections, row limits)
 * Aggregates results into consolidated diagnostic payload
 *
 * PARSER PIPELINE (17 parsers):
 * version → hardware (dependency) → disks, raid, volumes → network → logs, btrfs, dstate, etc.
 * Each parser: parse(extractedPath, context) => {data or error}
 * Context: Shared state (DSM version, hardware profile) passed down
 * Citations: Aggregated from parsers (audit trail for AI findings)
 *
 * EXECUTION MODEL:
 * parseAll() iterates parsers in registration order
 * Skips disabled sections (config[key]['is_enabled'] = false)
 * Never skips version/hardware (dependencies)
 * Max_rows passed via config to parsers that have limits
 * Per-parser failures caught; don't block other parsers
 *
 * OUTPUT:
 * Consolidated result: section_key => parsed_data
 * _citations key: Aggregated evidence references
 * expansion_units: Auto-extracted from disk container field
 *
 * @package App\Services
 */
class ParseService
{
    private array $parsers = [];

    /**
     * Constructor: Register default parsers
     *
     * REGISTRATION ORDER:
     * Critical: version, hardware (dependencies)
     * Storage: disks, raid, volumes, network
     * Logs: logs, btrfs, dstate, storage_util, disk_io, system_load, memory_util
     * Databases: audit_db, auth_timeline, smb_xfer
     *
     * Order matters: Later parsers may depend on earlier ones
     * Version/hardware always executed (required for context)
     * Others respect extraction config (can be disabled)
     *
     * All implement ParserInterface: parse(extractedPath, context)
     */
    public function __construct()
    {
        // Register default parsers in logical order
        $this->parsers = [
            'version' => new VersionParser(),
            'hardware' => new HardwareParser(),
            'disks' => new DiskParser(),
            'raid' => new RaidParser(),
            'volumes' => new VolumeParser(),
            'network' => new NetworkParser(),
            'network_hardware' => new NetworkHardwareParser(),
            'logs' => new LogParser(),
            'btrfs' => new BtrfsScrubParser(),
            'dstate' => new DStateParser(),
            'storage_util' => new DfResultParser(),
            'disk_io' => new DiskstatsParser(),
            'system_load' => new TopResultParser(),
            'memory_util' => new VmstatParser(),
            'audit_db' => new \App\Parsers\DatabaseParser(),
            'auth_timeline' => new \App\Parsers\AuthTimelineParser(),
            'smb_xfer' => new \App\Parsers\SmbXferParser(),
        ];
    }

    /**
     * Execute all parsers on extracted diagnostic data
     *
     * WORKFLOW:
     * 1. Validate extracted directory exists
     * 2. Prepare context with config [{_config: {...}}]
     * 3. For each registered parser:
     *    - Check if section enabled in config
     *    - Never skip version/hardware (dependencies)
     *    - Call parser->parse(extractedPath, context)
     *    - Store result (data or error)
     *    - Update context with version info if applicable
     * 4. Post-process: Extract expansion units from disk data
     * 5. Return consolidated results
     *
     * CONFIGURATION RESPECT:
     * config[key]['is_enabled']: Boolean (skip if false)
     * config[key]['max_rows']: Integer limit (passed to parser)
     * Context propagation: Version data shared with downstream parsers
     *
     * CITATION HANDLING:
     * Modern parsers return: {data, citations}
     * Citations aggregated into results['_citations'][section_key]
     * Fallback: Non-refactored parsers return data directly
     *
     * ERROR ISOLATION:
     * Per-parser exceptions caught
     * Failed section: {error: exception_message}
     * Other sections continue (no cascading failures)
     *
     * EXPANSION UNITS:
     * Post-processing step
     * Extracts unique 'container' values from disks
     * Filters out 'Main' unit (implicit)
     * Added to results['expansion_units'] array
     *
     * @param string $extractedPath Absolute path to extraction directory
     * @param array<string,array<string,mixed>> $config Runtime config from ExtractionConfigService
     *   Format: {section_key: {is_enabled: bool, max_rows: int|null}}
     *
     * @return array<string,mixed> Consolidated diagnostic data
     *   - Each key: parsed section data or error
     *   - _citations: Aggregated evidence references (if present)
     *   - expansion_units: List of external expansion unit names
     *
     * @throws RuntimeException If extracted path doesn't exist
     */
    public function parseAll(string $extractedPath, array $config = []): array
    {
        if (!is_dir($extractedPath)) {
            throw new RuntimeException("Extracted path does not exist: $extractedPath");
        }

        $results = [];
        // Inject config into context — parsers with inline limits read from here
        $context = ['_config' => $config];

        foreach ($this->parsers as $key => $parser) {
            // Skip disabled sections (never skip 'version' or 'hardware' — other parsers depend on them)
            if (!in_array($key, ['version', 'hardware'], true)) {
                $sectionEnabled = $config[$key]['is_enabled'] ?? true;
                if (!$sectionEnabled) {
                    continue; // Skip this parser entirely
                }
            }

            try {
                $response = $parser->parse($extractedPath, $context);
                
                // --- NEW: PHASE 1 INFRASTRUCTURE ---
                // Handle new citation-aware return structure: ['data' => ..., 'citations' => ...]
                if (isset($response['data'])) {
                    $results[$key] = $response['data'];
                    // We can aggregate citations into a separate key or keep them available
                    if (!isset($results['_citations'])) $results['_citations'] = [];
                    $results['_citations'][$key] = $response['citations'] ?? [];
                } else {
                    // Fallback for non-refactored parsers (deprecated style)
                    $results[$key] = $response;
                }
                // ------------------------------------

                // Update context for subsequent parsers
                if ($key === 'version') $context = array_merge($context, is_array($results[$key]) ? $results[$key] : []);
            } catch (\Exception $e) {
                $results[$key] = ['error' => $e->getMessage()];
            }
        }

        // Post-processing: Extract Expansion Units from Disks
        $expansionUnits = [];
        if (isset($results['disks']) && is_array($results['disks'])) {
            foreach ($results['disks'] as $disk) {
                $container = $disk['container'] ?? 'Main';
                if ($container !== 'Main' && !in_array($container, $expansionUnits)) {
                    $expansionUnits[] = $container;
                }
            }
        }
        $results['expansion_units'] = $expansionUnits;

        return $results;
    }

    /**
     * Register or override a parser
     *
     * USAGE:
     * Add custom or replacement parser after construction
     * Allows dependency injection of custom parsers for testing
     * Parser inserted at specified key (replaces if exists)
     *
     * EXAMPLE:
     * $service->addParser('disks', new CustomDiskParser())
     *
     * @param string $key Section key (e.g., 'disks', 'logs')
     * @param ParserInterface $parser Parser instance implementing parse()
     *
     * @return void
     */
    public function addParser(string $key, ParserInterface $parser): void
    {
        $this->parsers[$key] = $parser;
    }
}

