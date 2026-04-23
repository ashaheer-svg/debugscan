#!/usr/bin/env php
<?php
/**
 * CLI Directory Listing Tool
 * Usage: php list-directory.php [path] [--json] [--tree]
 *
 * Examples (run from bin/ directory):
 *   php list-directory.php                        # List entire project root
 *   php list-directory.php --json                 # Project root as JSON
 *   php list-directory.php sample                 # List sample directory
 *   php list-directory.php sample --json          # Sample as JSON
 *   php list-directory.php public/tools --tree    # Specific dir as tree
 *   php list-directory.php /var/www/other-path    # Absolute path
 */

$json = in_array('--json', $_SERVER['argv']);
$tree = in_array('--tree', $_SERVER['argv']);

// Get path - default to project root (parent of bin directory)
$path = null;
foreach ($_SERVER['argv'] as $arg) {
    if ($arg !== $argv[0] && !preg_match('/^--/', $arg)) {
        $path = $arg;
        break;
    }
}

// If no path provided, use project root (parent of bin/)
if (!$path) {
    $path = dirname(dirname(__FILE__));
}

// Make path absolute if relative
if (!preg_match('#^/#', $path)) {
    if ($path === '.') {
        $path = dirname(dirname(__FILE__));
    } else {
        $path = dirname(dirname(__FILE__)) . '/' . $path;
    }
}

$path = realpath($path);
if (!$path || !is_dir($path)) {
    fprintf(STDERR, "Error: Invalid path\n");
    exit(1);
}

$listing = [
    'path' => $path,
    'timestamp' => date('Y-m-d H:i:s'),
    'directories' => [],
    'files' => [],
    'stats' => [
        'total_size_bytes' => 0,
        'total_files' => 0,
        'total_dirs' => 0,
    ],
];

$iterator = new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS);
$filter = new RecursiveCallbackFilterIterator($iterator, function($current, $key, $iterator) {
    if ($current->getBasename()[0] === '.') return false;
    if (in_array($current->getBasename(), ['vendor', 'node_modules', '.git', '.vscode'])) return false;
    return true;
});

$recursive = new RecursiveIteratorIterator($filter);

foreach ($recursive as $fileinfo) {
    $relativePath = str_replace($path, '', $fileinfo->getPathname());
    $relativePath = ltrim($relativePath, '/\\');
    $size = $fileinfo->getSize();
    $listing['stats']['total_size_bytes'] += $size;

    if ($fileinfo->isDir()) {
        $listing['directories'][] = [
            'path' => $relativePath,
            'name' => $fileinfo->getBasename(),
        ];
        $listing['stats']['total_dirs']++;
    } else {
        $listing['files'][] = [
            'path' => $relativePath,
            'name' => $fileinfo->getBasename(),
            'size_bytes' => $size,
            'size_human' => formatBytes($size),
            'extension' => pathinfo($fileinfo->getFilename(), PATHINFO_EXTENSION),
            'modified' => date('Y-m-d H:i:s', $fileinfo->getMTime()),
        ];
        $listing['stats']['total_files']++;
    }
}

$listing['stats']['total_size_human'] = formatBytes($listing['stats']['total_size_bytes']);
usort($listing['directories'], fn($a, $b) => strcmp($a['path'], $b['path']));
usort($listing['files'], fn($a, $b) => strcmp($a['path'], $b['path']));

if ($json) {
    echo json_encode($listing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    // Human-readable output
    echo "\n";
    echo "┌─ Directory Listing Report\n";
    echo "│\n";
    echo "├─ Path:           " . $listing['path'] . "\n";
    echo "├─ Timestamp:      " . $listing['timestamp'] . "\n";
    echo "│\n";
    echo "├─ Statistics:\n";
    echo "│  ├─ Total Files:        " . $listing['stats']['total_files'] . "\n";
    echo "│  ├─ Total Directories:  " . $listing['stats']['total_dirs'] . "\n";
    echo "│  └─ Total Size:         " . $listing['stats']['total_size_human'] . "\n";
    echo "│\n";

    if (!$tree && count($listing['files']) > 0) {
        echo "├─ Files by Extension:\n";
        $byExt = [];
        foreach ($listing['files'] as $f) {
            $ext = $f['extension'] ?: 'other';
            if (!isset($byExt[$ext])) $byExt[$ext] = [];
            $byExt[$ext][] = $f;
        }
        ksort($byExt);
        $exts = array_keys($byExt);
        foreach ($exts as $i => $ext) {
            $files = $byExt[$ext];
            $total = array_sum(array_column($files, 'size_bytes'));
            $isLast = ($i === count($exts) - 1);
            $prefix = $isLast ? '└─' : '├─';
            echo "│  $prefix .$ext (" . count($files) . " files, " . formatBytes($total) . ")\n";
        }
        echo "│\n";
    }

    if ($tree || count($listing['files']) <= 50) {
        echo "└─ Directory Tree:\n";
        if (count($listing['directories']) > 0) {
            echo "\n   📁 Directories:\n";
            foreach ($listing['directories'] as $d) {
                $depth = substr_count($d['path'], '/');
                $indent = str_repeat('     ', $depth);
                echo "   $indent📂 " . $d['name'] . "\n";
            }
        }
        if (count($listing['files']) > 0) {
            echo "\n   📄 Files:\n";
            foreach ($listing['files'] as $f) {
                $depth = substr_count($f['path'], '/');
                $indent = str_repeat('     ', $depth);
                echo "   $indent📄 " . str_pad($f['name'], 40) . " " . str_pad($f['size_human'], 10, ' ', STR_PAD_LEFT) . "\n";
            }
        }
    }

    echo "\n";
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
