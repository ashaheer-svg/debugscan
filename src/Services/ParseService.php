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

class ParseService
{
    private array $parsers = [];

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
        ];
    }

    /**
     * Run all registered parsers on the extracted directory.
     * 
     * @param string $extractedPath
     * @param array  $config  Runtime config from ExtractionConfigService::getRuntimeConfig() (L1 only)
     * @return array Consolidated diagnostic data
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

    public function addParser(string $key, ParserInterface $parser): void
    {
        $this->parsers[$key] = $parser;
    }}

