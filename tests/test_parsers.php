<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Services\FileService;
use App\Services\ParseService;
use App\Database;

// Mock PDO for testing
$pdo = new PDO('sqlite::memory:');

$uploadDir = __DIR__ . '/../storage/uploads';
$extractDir = __DIR__ . '/../storage/extracted';
$sampleDir = __DIR__ . '/../sample';

$fileService = new FileService($pdo, $uploadDir, $extractDir);
$parseService = new ParseService();

$samples = glob($sampleDir . '/*.dat');

echo "AI DebugScan v3 - Parser Test Suite\n";
echo "==================================\n\n";

foreach ($samples as $sample) {
    $filename = basename($sample);
    $fileId = str_replace(['.dat', '.'], '', $filename) . '_test';
    
    echo "Processing: $filename ... ";
    
    try {
        // 1. Extract
        $extractedFiles = $fileService::processFile($fileId, $sample);
        echo "Extracted " . count($extractedFiles) . " files. ";
        
        // 2. Parse
        $destPath = $fileService->getExtractedPath($fileId);
        $results = $parseService->parseAll($destPath);
        
        echo "Parsed " . count($results) . " categories.\n";
        
        // Output summary findings
        $hw = $results['hardware'] ?? [];
        echo "  Model: " . ($hw['model'] ?? 'Unknown') . "\n";
        echo "  Serial: " . ($hw['serial'] ?? 'Unknown') . "\n";
        echo "  DSM Version: " . ($results['version']['product'] ?? 'Unknown') . "\n";
        echo "  Disks: " . count($results['disks'] ?? []) . "\n";
        echo "  RAIDs: " . count($results['raid'] ?? []) . "\n";
        echo "----------------------------------\n";
        
    } catch (\Exception $e) {
        echo "FAILED: " . $e->getMessage() . "\n";
    }
}
