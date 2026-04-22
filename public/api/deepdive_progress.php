<?php

declare(strict_types=1);

/**
 * DeepDive progress endpoint. Returns job status for polling clients.
 * Supports both JSON (fetch) and SSE (EventSource) formats.
 */

// Enable error logging
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

try {
    @require __DIR__ . '/../../vendor/autoload.php';

    if (!class_exists('App\Database')) {
        throw new \Exception('Database class not found after autoload');
    }

    use App\Database;
    use App\DeepDive\Services\JobRepository;
} catch (\Throwable $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    $msg = 'Initialization error: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    error_log('[DeepDive] ' . $msg);
    echo json_encode(['error' => $msg]);
    exit;
}

try {
    // Detect if client wants JSON (fetch) or SSE (EventSource)
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantJson = strpos($accept, 'application/json') !== false;

    // Set appropriate headers
    if ($wantJson) {
        header('Content-Type: application/json');
        header('Cache-Control: no-cache');
    } else {
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
    }

    // CORS headers
    $origin = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($origin) {
        $origin = parse_url($origin, PHP_URL_SCHEME) . '://' . parse_url($origin, PHP_URL_HOST);
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Accept, Content-Type');
    }

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Get and validate job ID
    $jobId = (string)($_GET['id'] ?? '');
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
        http_response_code(400);
        echo $wantJson
            ? json_encode(['error' => 'Invalid job ID format'])
            : "event: error\ndata: Invalid job ID format\n\n";
        exit;
    }

    // Get tenant context
    $tenantId = (string)($_SESSION['tenant_id'] ?? '');
    $role = (string)($_SESSION['role'] ?? 'tenant');
    if (!$tenantId && isset($_SERVER['HTTP_X_TENANT_ID'])) {
        $tenantId = (string)$_SERVER['HTTP_X_TENANT_ID'];
    }

    $pdo = Database::getConnection();
    Database::setTenantContext($pdo, $tenantId ?: null, $role);
    $jobs = new JobRepository($pdo);

    // For JSON (fetch): return immediately
    if ($wantJson) {
        $row = $jobs->findForTenant($jobId, $tenantId ?: null);
        if (!$row) {
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
            'id' => $row['id'],
            'status' => $row['status'],
            'progress_percent' => (int)($row['progress_percent'] ?? 0),
            'progress_stage' => $row['progress_stage'],
            'steps' => $steps,
            'error_message' => $row['error_message'],
            'report_ready' => $row['status'] === 'completed',
            'report_url' => $row['status'] === 'completed' ? '/deepdive/report/' . $row['id'] : null,
            'report_html_url' => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/html' : null,
            'report_pdf_url' => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/pdf' : null,
        ];

        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    // For SSE: stream updates every 2 seconds
    $maxSeconds = 1800;
    $started = time();

    while (true) {
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
            'id' => $row['id'],
            'status' => $row['status'],
            'progress_percent' => (int)($row['progress_percent'] ?? 0),
            'progress_stage' => $row['progress_stage'],
            'steps' => $steps,
            'error_message' => $row['error_message'],
            'report_ready' => $row['status'] === 'completed',
            'report_url' => $row['status'] === 'completed' ? '/deepdive/report/' . $row['id'] : null,
            'report_html_url' => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/html' : null,
            'report_pdf_url' => $row['status'] === 'completed' ? '/deepdive/download/' . $row['id'] . '/pdf' : null,
        ];

        echo "event: status\ndata: " . json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n\n";

        if (in_array($row['status'], ['completed', 'failed', 'cancelled'], true)) {
            break;
        }

        if (ob_get_level() > 0) @ob_flush();
        @flush();

        if ((time() - $started) > $maxSeconds) {
            echo "event: timeout\ndata: stream timeout\n\n";
            break;
        }

        sleep(2);
    }

} catch (\Throwable $e) {
    $errorMsg = 'Exception: ' . get_class($e) . ' - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine();
    error_log('[DeepDive API] ' . $errorMsg);

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    // Return detailed error for debugging
    echo json_encode([
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ], JSON_PRETTY_PRINT);
}
