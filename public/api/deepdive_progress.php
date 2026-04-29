<?php

use App\Database;
use App\DeepDive\Services\JobRepository;

header('Content-Type: application/json');

try {
    $jobId = $_GET['id'] ?? '';

    // Validate UUID format
    if (!preg_match('/^[0-9a-f\-]{36}$/i', $jobId)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid job ID']);
        exit;
    }

    // Load app
    require __DIR__ . '/../../vendor/autoload.php';

    // Get database connection
    $pdo = Database::getConnection();
    $jobs = new JobRepository($pdo);

    // Look up job - try without tenant context first
    $row = $jobs->findForTenant($jobId, null);

    if (!$row) {
        http_response_code(404);
        echo json_encode(['error' => 'Job not found']);
        exit;
    }

    // Parse steps
    $steps = [];
    if (!empty($row['steps_json'])) {
        $steps = json_decode($row['steps_json'], true) ?: [];
    }

    // Return job status with project ID for navigation
    echo json_encode([
        'id' => $row['id'],
        'project_id' => $row['project_id'],
        'status' => $row['status'],
        'progress_percent' => (int)($row['progress_percent'] ?? 0),
        'progress_stage' => $row['progress_stage'],
        'steps' => $steps,
        'error_message' => $row['error_message'],
        'report_ready' => $row['status'] === 'completed',
        'report_url' => '/deepdive/report/' . $row['id'],
        'report_html_url' => '/deepdive/download/' . $row['id'] . '/html',
        'report_pdf_url' => '/deepdive/download/' . $row['id'] . '/pdf',
        'project_url' => '/project/' . $row['project_id'],
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
