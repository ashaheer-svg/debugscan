<?php

declare(strict_types=1);

header('Content-Type: application/json');
header('Cache-Control: no-cache');

try {
    // Parse request
    $jobId = (string)($_GET['id'] ?? '');
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantJson = strpos($accept, 'application/json') !== false;

    // Validate job ID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
        http_response_code(400);
        return json_encode(['error' => 'Invalid job ID']);
    }

    // Load application
    require __DIR__ . '/../../vendor/autoload.php';

    use App\Database;
    use App\DeepDive\Services\JobRepository;

    // Get database connection
    $tenantId = (string)($_SESSION['tenant_id'] ?? '');
    $role = (string)($_SESSION['role'] ?? 'tenant');

    $pdo = Database::getConnection();
    Database::setTenantContext($pdo, $tenantId ?: null, $role);
    $jobs = new JobRepository($pdo);

    // Look up job
    $row = $jobs->findForTenant($jobId, $tenantId ?: null);
    if (!$row) {
        http_response_code(404);
        return json_encode(['error' => 'Job not found']);
    }

    // Parse steps
    $steps = [];
    if (!empty($row['steps_json'])) {
        $decoded = json_decode((string)$row['steps_json'], true);
        if (is_array($decoded)) $steps = $decoded;
    }

    // Build payload
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

    // For JSON: return immediately
    if ($wantJson) {
        http_response_code(200);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    // For SSE: stream updates
    header('Content-Type: text/event-stream');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

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
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
}
