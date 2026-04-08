<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Services\ScanService;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering for Nginx

$jobId = $_GET['id'] ?? null;
if (!$jobId) {
    echo "event: error\ndata: No job ID provided\n\n";
    exit;
}

$pdo = Database::getConnection();
$scanService = new ScanService($pdo);

// Start SSE Loop
while (true) {
    try {
        $status = $scanService->getJobStatus($jobId);
        
        echo "event: status\ndata: " . json_encode($status) . "\n\n";
        
        if (in_array($status['status'], ['completed', 'failed', 'cancelled'])) {
            break;
        }
        
    } catch (\Exception $e) {
        echo "event: error\ndata: " . $e->getMessage() . "\n\n";
        break;
    }

    if (ob_get_level() > 0) ob_flush();
    flush();
    
    sleep(2); // Poll status every 2 seconds
}
