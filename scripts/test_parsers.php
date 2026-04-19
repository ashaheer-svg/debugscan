<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\FileService;
use App\Services\ParseService;
use App\Helpers\ZipHelper;

/**
 * Parser Regression Test Harness
 * 
 * Usage: php scripts/test_parsers.php [--generate]
 */

$generate = in_array('--generate', $argv);
$sampleDir = __DIR__ . '/../sample';
$snapshotDir = __DIR__ . '/../tests/snapshots';
$tmpBase = __DIR__ . '/../scratch/test_tmp';

if (!is_dir($snapshotDir)) mkdir($snapshotDir, 0777, true);
if (!is_dir($tmpBase)) mkdir($tmpBase, 0777, true);

$parseService = new ParseService();

$datFiles = glob($sampleDir . '/*.dat');

echo "Forensic Parser Test Harness\n";
echo "============================\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'generated' => 0,
    'errors' => []
];

foreach ($datFiles as $datFile) {
    $baseName = basename($datFile);
    $snapshotFile = $snapshotDir . '/' . $baseName . '.json';
    $extractPath = $tmpBase . '/' . $baseName . '_extracted';

    echo "Testing $baseName... ";

    try {
        // 1. Extract
        if (!is_dir($extractPath)) {
            mkdir($extractPath, 0777, true);
            $zip = new ZipArchive();
            if ($zip->open($datFile) === true) {
                $zip->extractTo($extractPath);
                $zip->close();
                // Hook .xz pipeline for full forensic testing
                ZipHelper::decompressXzFiles($extractPath);
            } else {
                throw new Exception("Failed to open zip: $datFile");
            }
        }

        // 2. Parse
        $data = $parseService->parseAll($extractPath);

        // 3. Compare or Generate
        if (!file_exists($snapshotFile) || $generate) {
            file_put_contents($snapshotFile, json_encode($data, JSON_PRETTY_PRINT));
            echo "SNAPSHOT GENERATED\n";
            $results['generated']++;
        } else {
            $snapshot = json_decode(file_get_contents($snapshotFile), true);
            
            // Compare structure and keys (values might change slightly if timestamps are non-deterministic, 
            // but for static .dat they should be stable)
            if ($data['_citations'] !== $snapshot['_citations']) {
                 echo "FAILED (Citation mismatch)\n";
                 $results['failed']++;
                 $results['errors'][] = "$baseName: Citation data mismatch";
                 continue;
            }

            // check top-level keys
            $diff = array_diff(array_keys($data), array_keys($snapshot));
            if (!empty($diff)) {
                echo "FAILED (Key mismatch: " . implode(', ', $diff) . ")\n";
                $results['failed']++;
                $results['errors'][] = "$baseName: Missing or extra top-level keys";
                continue;
            }

            echo "PASSED\n";
            $results['passed']++;
        }

    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        $results['failed']++;
        $results['errors'][] = "$baseName: " . $e->getMessage();
    }
}

echo "\nSummary\n";
echo "-------\n";
echo "Passed:    {$results['passed']}\n";
echo "Failed:    {$results['failed']}\n";
echo "Generated: {$results['generated']}\n";

if (!empty($results['errors'])) {
    echo "\nError Details:\n";
    foreach ($results['errors'] as $err) {
        echo "- $err\n";
    }
    exit(1);
}

exit(0);
