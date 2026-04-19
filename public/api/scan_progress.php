<?php

declare(strict_types=1);

require __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\Services\ScanService;
use App\Services\ReportPlanService;

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); // Disable buffering for Nginx

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['user_id'])) {
    echo "event: error\ndata: Unauthorized access\n\n";
    exit;
}

$tenantId = $_SESSION['tenant_id'] ?? null;
$role = $_SESSION['role'] ?? 'tenant';

$pdo = Database::getConnection();
$reportPlanService = new ReportPlanService($pdo);
$scanService = new ScanService($pdo, $reportPlanService);

$jobId = $_GET['id'] ?? null;
if (!$jobId) {
    echo "event: error\ndata: Missing job ID\n\n";
    exit;
}

// Start SSE Loop
while (true) {
    try {
        // Admins can see any job, tenants only their own
        $status = $scanService->getJobStatus((string)$jobId, $role === 'admin' ? null : (string)$tenantId);
        
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
