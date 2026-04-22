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

// Get origin from request
$origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
if ($origin) {
    $origin = parse_url($origin, PHP_URL_SCHEME) . '://' . parse_url($origin, PHP_URL_HOST);
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Methods: GET, OPTIONS');
    header('Access-Control-Allow-Headers: Accept, Content-Type');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$jobId = (string)($_GET['id'] ?? '');

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
    http_response_code(400);
    if ($wantJson) {
        echo json_encode(['error' => 'Invalid job ID format']);
    } else {
        echo "event: error\ndata: Invalid job ID format\n\n";
    }
    exit;
}

// Get tenant from session if available, otherwise default
$tenantId = (string)($_SESSION['tenant_id'] ?? '');
$role     = (string)($_SESSION['role'] ?? 'tenant');

// If no session, try to get tenant from header (fallback for fetch polling)
if (!$tenantId && isset($_SERVER['HTTP_X_TENANT_ID'])) {
    $tenantId = (string)$_SERVER['HTTP_X_TENANT_ID'];
}

// Job status is considered low-sensitivity - if someone has the jobId UUID,
// they accessed it through the web interface and have implicit permission to poll it.
// Session is optional here for polling compatibility.

$pdo = Database::getConnection();
Database::setTenantContext($pdo, $tenantId ?: null, $role);

$jobs = new JobRepository($pdo);

// For fetch (JSON): return current status immediately
if ($wantJson) {
    try {
        error_log("[DeepDive API] Looking up jobId: $jobId, tenantId: " . ($tenantId ?: 'null'));

        $row = $jobs->findForTenant($jobId, $tenantId ?: null);
        if (!$row) {
            error_log("[DeepDive API] Job not found: $jobId");
            http_response_code(404);
            echo json_encode(['error' => 'Job not found']);
            exit;
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

        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        error_log("[DeepDive API] Error: " . $e->getMessage() . "\n" . $e->getTraceAsString());
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    }
    exit;
}

// For SSE: stream updates every 2 seconds
$maxSeconds = 1800;
$started    = time();

while (true) {
    try {
        $row = $jobs->findForTenant($jobId, $tenantId ?: null);
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

        // SSE format (wantJson already exited above)
        echo "event: status\ndata: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

        if (in_array($row['status'], ['completed','failed','cancelled'], true)) {
            break;
        }
    } catch (\Throwable $e) {
        // SSE format (wantJson already exited above)
        echo "event: error\ndata: " . $e->getMessage() . "\n\n";
        break;
    }

    if (ob_get_level() > 0) @ob_flush();
    @flush();

    if ((time() - $started) > $maxSeconds) {
        // SSE format (wantJson already exited above)
        echo "event: timeout\ndata: stream timeout\n\n";
        break;
    }
    sleep(2);
}
