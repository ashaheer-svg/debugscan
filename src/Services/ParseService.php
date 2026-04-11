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
     * @return array Consolidated diagnostic data
     */
    public function parseAll(string $extractedPath): array
    {
        if (!is_dir($extractedPath)) {
            throw new RuntimeException("Extracted path does not exist: $extractedPath");
        }

        $results = [];
        $context = []; // Shared state between parsers (e.g. DSM version)

        foreach ($this->parsers as $key => $parser) {
            try {
                $results[$key] = $parser->parse($extractedPath, $context);
                // Update context for subsequent parsers
                if ($key === 'version') $context = array_merge($context, $results[$key]);
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
