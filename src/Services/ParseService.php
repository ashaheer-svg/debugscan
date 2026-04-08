<?php

declare(strict_types=1);

namespace App\Services;

use App\Parsers\ParserInterface;
use App\Parsers\VersionParser;
use App\Parsers\HardwareParser;
use App\Parsers\DiskParser;
use App\Parsers\RaidParser;
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
            } catch (\Exception $e) {
                $results[$key] = ['error' => $e->getMessage()];
            }
        }

        return $results;
    }

    public function addParser(string $key, ParserInterface $parser): void
    {
        $this->parsers[$key] = $parser;
    }
}
