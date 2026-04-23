<?php
/**
 * Test endpoint to verify DiskParser is extracting expansion units correctly
 *
 * Usage: Open in browser at http://your-server/test-diskparser.php?path=/extracted/debug/bundle/path
 * Or from CLI: php public/test-diskparser.php /extracted/path
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Parsers\DiskParser;

// Get extracted path from query param or CLI arg
$extractedPath = null;

if (php_sapi_name() === 'cli') {
    // CLI usage
    $extractedPath = $argv[1] ?? null;
} else {
    // Web usage
    $extractedPath = $_GET['path'] ?? null;
}

if (!$extractedPath) {
    if (php_sapi_name() === 'cli') {
        echo "Usage: php public/test-diskparser.php /path/to/extracted/bundle\n";
        exit(1);
    }
    echo "<h2>DiskParser Test</h2>";
    echo "<p>Usage: <code>?path=/path/to/extracted/bundle</code></p>";
    exit(0);
}

// Verify path exists
if (!is_dir($extractedPath)) {
    $msg = "Error: Path does not exist: $extractedPath";
    if (php_sapi_name() === 'cli') {
        echo "$msg\n";
    } else {
        echo "<p style='color: red;'>$msg</p>";
    }
    exit(1);
}

try {
    $parser = new DiskParser();
    $result = $parser->parse($extractedPath, $context = []);

    if (php_sapi_name() === 'cli') {
        // CLI output
        echo "=== DiskParser Test Results ===\n\n";
        echo "Extracted Path: $extractedPath\n";
        echo "Total Disks Found: " . count($result['data']) . "\n\n";

        echo "Disk Details:\n";
        echo str_repeat("-", 100) . "\n";
        printf("%-8s %-15s %-30s %-15s %-10s %-12s\n",
            "Device", "Container", "Model", "Serial", "Size GB", "Temp");
        echo str_repeat("-", 100) . "\n";

        foreach ($result['data'] as $device => $disk) {
            printf("%-8s %-15s %-30s %-15s %-10s %-12s\n",
                $device,
                $disk['container'] ?? 'N/A',
                substr($disk['model'] ?? '', 0, 29),
                substr($disk['serial'] ?? '', 0, 14),
                $disk['size_gb'] ?? 0,
                ($disk['temp'] ?? 0) . '°C'
            );
        }
        echo str_repeat("-", 100) . "\n";

        // Summary
        $expansion = array_filter($result['data'], fn($d) =>
            strpos($d['container'] ?? '', 'Expansion') !== false
        );
        echo "\nSummary:\n";
        echo "  Main Disks: " . (count($result['data']) - count($expansion)) . "\n";
        echo "  Expansion Disks: " . count($expansion) . "\n";

        if (count($expansion) > 0) {
            echo "\nExpansion Drives Detected:\n";
            foreach ($expansion as $device => $disk) {
                echo "  - $device: " . $disk['container'] . " (" . ($disk['size_gb'] ?? 0) . " GB)\n";
            }
        }

    } else {
        // Web output
        echo "<h2>DiskParser Test Results</h2>";
        echo "<p><strong>Path:</strong> " . htmlspecialchars($extractedPath) . "</p>";
        echo "<p><strong>Total Disks:</strong> " . count($result['data']) . "</p>";

        echo "<table border='1' cellpadding='8' cellspacing='0'>";
        echo "<thead><tr>";
        echo "<th>Device</th><th>Container</th><th>Model</th><th>Serial</th>";
        echo "<th>Size (GB)</th><th>Temp</th><th>SMART</th>";
        echo "</tr></thead><tbody>";

        foreach ($result['data'] as $device => $disk) {
            $expansion = strpos($disk['container'] ?? '', 'Expansion') !== false;
            $color = $expansion ? '#fff3cd' : '#fff';
            echo "<tr style='background-color: $color;'>";
            echo "<td><strong>" . htmlspecialchars($device) . "</strong></td>";
            echo "<td>" . htmlspecialchars($disk['container'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($disk['model'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($disk['serial'] ?? 'N/A') . "</td>";
            echo "<td>" . ($disk['size_gb'] ?? 0) . "</td>";
            echo "<td>" . ($disk['temp'] ?? 0) . "°C</td>";
            echo "<td>" . htmlspecialchars($disk['smart_status'] ?? 'N/A') . "</td>";
            echo "</tr>";
        }

        echo "</tbody></table>";

        $expansion = array_filter($result['data'], fn($d) =>
            strpos($d['container'] ?? '', 'Expansion') !== false
        );

        echo "<hr>";
        echo "<h3>Summary</h3>";
        echo "<ul>";
        echo "<li>Main Disks: " . (count($result['data']) - count($expansion)) . "</li>";
        echo "<li>Expansion Disks: " . count($expansion) . "</li>";
        if (count($expansion) > 0) {
            echo "<li><strong style='color: green;'>✓ Expansion detection working!</strong></li>";
        } else {
            echo "<li><strong style='color: red;'>✗ No expansion disks detected</strong></li>";
        }
        echo "</ul>";
    }

} catch (\Exception $e) {
    $msg = "Parser Error: " . $e->getMessage();
    if (php_sapi_name() === 'cli') {
        echo "$msg\n";
    } else {
        echo "<p style='color: red;'>$msg</p>";
        echo "<pre>" . $e->getTraceAsString() . "</pre>";
    }
    exit(1);
}
