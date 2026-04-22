<?php

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json');
header('Cache-Control: no-cache');

try {
    // Step 1: Autoload
    $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new \Exception("Autoload file not found: $autoloadPath");
    }
    require $autoloadPath;

    // Step 2: Validate classes exist
    if (!class_exists('App\Database')) {
        throw new \Exception('App\Database class not found');
    }
    if (!class_exists('App\DeepDive\Services\JobRepository')) {
        throw new \Exception('JobRepository class not found');
    }

    // Step 3: Parse request
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    $wantJson = strpos($accept, 'application/json') !== false;
    $jobId = (string)($_GET['id'] ?? '');

    // Step 4: Validate job ID
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job ID format']);
        exit;
    }

    // Step 5: Get database connection
    use App\Database;
    use App\DeepDive\Services\JobRepository;

    $tenantId = (string)($_SESSION['tenant_id'] ?? '');
    $role = (string)($_SESSION['role'] ?? 'tenant');

    $pdo = Database::getConnection();
    Database::setTenantContext($pdo, $tenantId ?: null, $role);
    $jobs = new JobRepository($pdo);

    // Step 6: For JSON requests, return immediately
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

    // Step 7: For SSE, stream updates
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
    if (!headers_sent()) {
        http_response_code(500);
    }

    $errorData = [
        'error' => $e->getMessage(),
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ];

    error_log('[DeepDive API] ' . json_encode($errorData));

    if (headers_sent()) {
        echo "\n\nevent: error\ndata: " . json_encode($errorData) . "\n\n";
    } else {
        echo json_encode($errorData);
    }
}
