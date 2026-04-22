<?php

declare(strict_types=1);

/**
 * DeepDive SSE progress endpoint. Mirrors public/api/scan_progress.php but
 * reads from deepdive_jobs. Completely isolated — removing this file only
 * breaks DeepDive progress streaming.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database;
use App\DeepDive\Services\JobRepository;

// Detect if client wants JSON (fetch) or SSE (EventSource)
$accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
$wantJson = strpos($accept, 'application/json') !== false;

if ($wantJson) {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache');
} else {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
}
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Credentials: true');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$jobId = (string)($_GET['id'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
    http_response_code(400);
    echo "event: error\ndata: Invalid job ID format\n\n";
    exit;
}

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo "event: error\ndata: Unauthorized - Please log in\n\n";
    exit;
}

$tenantId = (string)($_SESSION['tenant_id'] ?? '');
$role     = (string)($_SESSION['role'] ?? 'tenant');

$pdo = Database::getConnection();
Database::setTenantContext($pdo, $tenantId ?: null, $role);

$jobs = new JobRepository($pdo);

$maxSeconds = 1800; // 30 min hard cap on an SSE connection
$started    = time();

while (true) {
    try {
        $row = $jobs->findForTenant($jobId, $tenantId);
        if (!$row) {
            echo "event: error\ndata: Job not found\n\n";
            break;
        }

        $steps = [];
        if (!empty($row['steps_json'])) {
            $decoded = json_decode((string)$row['steps_json'], true);
            if (is_array($decoded)) $steps = $decoded;
        }

        $payload = [
            'id'              => $row['id'],
            'status'          => $row['status'],
            'progress_percent'=> (int)($row['progress_percent'] ?? 0),
            'progress_stage'  => $row['progress_stage'],
            'steps'           => $steps,
            'error_message'   => $row['error_message'],
            'report_ready'    => $row['status'] === 'completed',
            'report_url'      => $row['status'] === 'completed' ? '/deepdive/report/' . $row['id'] : null,
            'report_html_url' => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/html' : null,
            'report_pdf_url'  => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/pdf' : null,
        ];

        if ($wantJson) {
            echo json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "event: status\ndata: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";
        }

        if (in_array($row['status'], ['completed','failed','cancelled'], true)) {
            break;
        }
    } catch (\Throwable $e) {
        $errorPayload = ['error' => $e->getMessage()];
        if ($wantJson) {
            echo json_encode($errorPayload, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "event: error\ndata: " . $e->getMessage() . "\n\n";
        }
        break;
    }

    if (ob_get_level() > 0) @ob_flush();
    @flush();

    if ((time() - $started) > $maxSeconds) {
        $timeoutPayload = ['error' => 'stream timeout'];
        if ($wantJson) {
            echo json_encode($timeoutPayload, JSON_UNESCAPED_SLASHES) . "\n";
        } else {
            echo "event: timeout\ndata: stream timeout\n\n";
        }
        break;
    }
    sleep(2);
}
