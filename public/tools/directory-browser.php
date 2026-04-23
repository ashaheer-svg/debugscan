<?php
/**
 * Directory Listing API
 * Shows file inventory of DeepDive directory with sizes and metadata
 * Usage: GET /tools/directory-browser.php?path=/path/to/dir
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json');

try {
    $path = $_GET['path'] ?? '/sessions/brave-vigilant-cannon/mnt/ai-debugscan3';

    // Sanitize path
    $path = realpath($path);
    if (!$path || !is_dir($path)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid path']);
        exit;
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
        // Skip hidden directories and common build/vendor dirs
        if ($current->getBasename()[0] === '.') return false;
        if (in_array($current->getBasename(), ['vendor', 'node_modules', '.git'])) return false;
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

    // Convert total bytes to human format
    $listing['stats']['total_size_human'] = formatBytes($listing['stats']['total_size_bytes']);

    // Sort by path
    usort($listing['directories'], fn($a, $b) => strcmp($a['path'], $b['path']));
    usort($listing['files'], fn($a, $b) => strcmp($a['path'], $b['path']));

    echo json_encode($listing, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << (10 * $pow));
    return round($bytes, $precision) . ' ' . $units[$pow];
}
