<?php

declare(strict_types=1);

namespace App\Parsers;

interface ParserInterface
{
    /**
     * Parse data from the extracted files in the given directory.
     * 
     * @param string $extractedPath Absolute path to the folder containing extracted files.
     * @param array $context Current session or shared state (e.g. DSM version).
     * @return array
     */
    public function parse(string $extractedPath, array &$context): array;
}
