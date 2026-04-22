<?php
// Minimal test to verify API endpoint works

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');

try {
    $jobId = $_GET['id'] ?? 'unknown';

    // Test 1: Can we output?
    echo json_encode([
        'test' => 'success',
        'jobId' => $jobId,
        'timestamp' => time(),
        'php_version' => phpversion(),
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
