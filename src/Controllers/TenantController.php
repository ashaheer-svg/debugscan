<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileService;
use App\Services\ParseService;
use App\Services\ScanService;
use App\Services\MailService;
use PDO;
use Ramsey\Uuid\Uuid;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class TenantController
{
    private Environment $view;
    private PDO $pdo;
    private FileService $fileService;
    private ScanService $scanService;
    private ParseService $parseService;
    private string $basePath;
    private MailService $mailService;
    private \App\Services\ReportPlanService $reportPlanService;

    public function __construct(
        Environment $view, 
        PDO $pdo, 
        FileService $fileService, 
        ScanService $scanService, 
        ParseService $parseService, 
        string $basePath,
        MailService $mailService,
        \App\Services\ReportPlanService $reportPlanService
    ) {
        $this->view = $view;
        $this->pdo = $pdo;
        $this->fileService = $fileService;
        $this->scanService = $scanService;
        $this->parseService = $parseService;
        $this->basePath = $basePath;
        $this->mailService = $mailService;
        $this->reportPlanService = $reportPlanService;
    }

    public function dashboard(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch KPIs
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM projects WHERE tenant_id = :tid");
        $stmt->execute(['tid' => $tenantId]);
        $projectCount = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM scan_jobs WHERE tenant_id = :tid AND status = 'completed'");
        $stmt->execute(['tid' => $tenantId]);
        $scanCount = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT tokens_available FROM users WHERE id = :tid");
        $stmt->execute(['tid' => $tenantId]);
        $tokensAvailable = $stmt->fetchColumn() ?: 0;

        // Recent Scans
        $stmt = $this->pdo->prepare("
            SELECT s.id, s.project_id, p.name as project_name, s.status, s.health_score, s.completed_at 
            FROM scan_jobs s
            JOIN projects p ON s.project_id = p.id
            WHERE s.tenant_id = :tid
            ORDER BY s.created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['tid' => $tenantId]);
        $recentScans = $stmt->fetchAll();

        $body = $this->view->render('tenant/dashboard.twig', [
            'project_count' => (int)$projectCount,
            'scan_count' => (int)$scanCount,
            'tokens_available' => (int)$tokensAvailable,
            'recent_scans' => $recentScans,
            'user_name' => $_SESSION['name'] ?? 'User',
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function scans(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch ALL jobs for this tenant
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.name as project_name 
            FROM scan_jobs s
            JOIN projects p ON s.project_id = p.id
            WHERE s.tenant_id = :tid
            ORDER BY s.created_at DESC
        ");
        $stmt->execute(['tid' => $tenantId]);
        $scans = $stmt->fetchAll();

        $body = $this->view->render('tenant/scans.twig', [
            'scans' => $scans,
            'active_page' => 'scans'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function projects(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE tenant_id = :tid ORDER BY created_at DESC");
        $stmt->execute(['tid' => $tenantId]);
        $projects = $stmt->fetchAll();

        $body = $this->view->render('tenant/projects.twig', [
            'projects' => $projects,
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function createProject(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("INSERT INTO projects (tenant_id, name, notes) VALUES (:tid, :name, :notes)");
        $stmt->execute([
            'tid' => $tenantId,
            'name' => $data['name'] ?? 'New Project',
            'notes' => $data['notes'] ?? '',
        ]);

        return $response->withHeader('Location', $this->basePath . '/projects')->withStatus(302);
    }

    public function deleteProject(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // 1. Verify existence and ownership
        $stmt = $this->pdo->prepare("SELECT id FROM projects WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $project = $stmt->fetch();

        if (!$project) {
            $_SESSION['error'] = "Project not found.";
            return $response->withHeader('Location', $this->basePath . '/projects')->withStatus(302);
        }

        // 2. Fetch all debug files for physical cleanup
        $stmt = $this->pdo->prepare("SELECT id, storage_path FROM debug_files WHERE project_id = :pid AND tenant_id = :tid");
        $stmt->execute(['pid' => $id, 'tid' => $tenantId]);
        $files = $stmt->fetchAll();

        foreach ($files as $file) {
            // deleteProjectFile handles both raw archive and extracted folder
            $this->fileService->deleteProjectFile($file['id'], $file['storage_path']);
        }

        // 3. Database Deletion (Cascades handle scans, findings, extraction errors)
        $stmt = $this->pdo->prepare("DELETE FROM projects WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);

        $this->logAction($request, 'project_deleted', 'projects', $id, ['id' => $id]);
        $_SESSION['success'] = "Forensic project and all associated logs/scans have been permanently deleted.";

        return $response->withHeader('Location', $this->basePath . '/projects')->withStatus(302);
    }

    public function viewProject(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch Project
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $project = $stmt->fetch();

        if (!$project) {
            return $response->withHeader('Location', $this->basePath . '/projects')->withStatus(302);
        }

        // Fetch Files
        $stmt = $this->pdo->prepare("SELECT * FROM debug_files WHERE project_id = :pid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $id]);
        $files = $stmt->fetchAll();

        // Fetch Scans
        $stmt = $this->pdo->prepare("
            SELECT s.*, p.name as package_name 
            FROM scan_jobs s
            LEFT JOIN report_plans p ON s.report_plan_id = p.id
            WHERE s.project_id = :pid 
            ORDER BY s.created_at DESC
        ");
        $stmt->execute(['pid' => $id]);
        $scans = $stmt->fetchAll();

        // Build mapping of file_id => array of completed scans
        $fileReports = [];
        foreach ($scans as $scan) {
            if ($scan['status'] !== 'completed') continue;
            
            // Parse Postgres array string like "{uuid1,uuid2}"
            $fids = explode(',', trim($scan['debug_file_ids'], '{}'));
            
            foreach ($fids as $fid) {
                if (!isset($fileReports[$fid])) {
                    $fileReports[$fid] = [];
                }
                
                $fileReports[$fid][] = [
                    'id'           => $scan['id'],
                    'package_name' => $scan['package_name'] ?? $scan['scan_level'],
                    'truncated'    => (bool)($scan['is_truncated'] ?? false),
                    'completed_at' => $scan['completed_at']
                ];
            }
        }

        // Fetch authorized plans for this tenant
        $authorizedPlans = $this->reportPlanService->getPlansForTenant($tenantId);

        $body = $this->view->render('tenant/project_view.twig', [
            'project' => $project,
            'files' => $files,
            'scans' => $scans,
            'plans' => $authorizedPlans,
            'file_reports' => $fileReports,
            'active_page' => 'projects',
            'success' => $_SESSION['success'] ?? null,
            'error' => $_SESSION['error'] ?? null,
        ]);

        unset($_SESSION['success'], $_SESSION['error']);

        $response->getBody()->write($body);
        return $response;
    }

    public function uploadLog(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['debug_log'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['error'] = 'Please select a valid Synology debug log file (.dat).';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        // 1. Security Check: File Size Limit (500MB)
        $maxSize = 500 * 1024 * 1024;
        if ($file->getSize() > $maxSize) {
            $_SESSION['error'] = 'File too large. Maximum allowed size is 500MB.';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        // 2. Extension Validation (Initial filter)
        $filename = $file->getClientFilename();
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'dat' && $ext !== 'zip') {
            $_SESSION['error'] = 'Invalid file format. Please upload a .dat or .zip file.';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        // 3. MIME Validation (Magic Bytes)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file->getFilePath());
        finfo_close($finfo);

        $allowedMimes = ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'];
        if (!in_array($mime, $allowedMimes)) {
            $_SESSION['error'] = 'Security Error: File content does not match allowed types.';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        try {
            // 1. Save File
            $fileId = Uuid::uuid4()->toString(); // We'll use this for storage
            $storedName = $fileId . '.dat';
            $uploadPath = __DIR__ . '/../../storage/uploads/' . $storedName;
            
            if (!is_dir(dirname($uploadPath))) {
                mkdir(dirname($uploadPath), 0755, true);
            }
            
            $file->moveTo($uploadPath);

            // 2. Database Record
            $stmt = $this->pdo->prepare("
                INSERT INTO debug_files (id, project_id, tenant_id, original_filename, stored_filename, file_size_bytes, file_hash_sha256, storage_path, extraction_status)
                VALUES (:id, :pid, :tid, :orig, :stored, :size, :hash, :path, 'pending')
            ");
            $stmt->execute([
                'id' => $fileId,
                'pid' => $id,
                'tid' => $tenantId,
                'orig' => $filename,
                'stored' => $storedName,
                'size' => $file->getSize(),
                'hash' => hash_file('sha256', $uploadPath),
                'path' => $uploadPath
            ]);
            
            // Fetch project name for better logging
            $stmt = $this->pdo->prepare("SELECT name FROM projects WHERE id = :pid");
            $stmt->execute(['pid' => $id]);
            $projectName = $stmt->fetchColumn() ?: 'Unknown';

            // Logging
            $this->logAction($request, 'file_uploaded', 'debug_files', $fileId, [
                'filename' => $filename,
                'project_name' => $projectName,
                'size' => $file->getSize()
            ]);

            // 3. Process/Extract
            $this->fileService->processFile($fileId, $uploadPath);

            // 4. Advanced Hardware Extraction (Hardwarev2.md)
            $extractedPath = $this->fileService->getExtractedPath($fileId);
            $parsedData = $this->parseService->parseAll($extractedPath);

            $hw = $parsedData['hardware'] ?? [];
            $ver = $parsedData['version'] ?? [];
            
            // Update Debug File record
            $stmt = $this->pdo->prepare("
                UPDATE debug_files SET 
                    extraction_status = 'completed',
                    dsm_version = :dsm,
                    nas_model = :model,
                    nas_serial = :serial,
                    extraction_data = :data
                WHERE id = :id
            ");
            $stmt->execute([
                'dsm' => $ver['product'] ?? null,
                'model' => $hw['model'] ?? null,
                'serial' => $hw['serial'] ?? null,
                'data' => json_encode($parsedData),
                'id' => $fileId
            ]);

            // Update Project record with authoritative hardware info
            $stmt = $this->pdo->prepare("
                UPDATE projects SET
                    model = COALESCE(model, :model),
                    serial_number = COALESCE(serial_number, :serial),
                    dsm_version = COALESCE(dsm_version, :dsm),
                    ram_gb = COALESCE(ram_gb, :ram),
                    cpu_model = COALESCE(cpu_model, :cpu),
                    physical_location = COALESCE(physical_location, :loc),
                    updated_at = NOW()
                WHERE id = :pid
            ");
            $stmt->execute([
                'model' => $hw['model'] ?? null,
                'serial' => $hw['serial'] ?? null,
                'dsm' => $ver['product'] ?? null,
                'ram' => $hw['ram_gb'] ?? null,
                'cpu' => $hw['cpu_model'] ?? null,
                'loc' => $hw['location'] ?? null,
                'pid' => $id
            ]);

            // --- EXTRACTED SERIAL VALIDATION ---
            $extractedSerial = $hw['serial'] ?? null;
            
            $stmt = $this->pdo->prepare("SELECT serial_number FROM projects WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $currentSerial = $stmt->fetchColumn();

            if ($extractedSerial && $currentSerial && $extractedSerial !== $currentSerial) {
                // Search for existing matching project for this tenant
                $stmt = $this->pdo->prepare("SELECT id, name FROM projects WHERE tenant_id = :tid AND serial_number = :sn AND id != :cid LIMIT 1");
                $stmt->execute(['tid' => $tenantId, 'sn' => $extractedSerial, 'cid' => $id]);
                $matchingProject = $stmt->fetch();

                if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                    $response->getBody()->write(json_encode([
                        'status' => 'mismatch',
                        'extracted_serial' => $extractedSerial,
                        'current_serial' => $currentSerial,
                        'matching_project' => $matchingProject,
                        'file_id' => $fileId,
                        'original_filename' => $filename
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(409);
                }
            }
            // ------------------------------------

            // If AJAX, return JSON
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'message' => 'Log file uploaded and processed successfully.',
                    'file_id' => $fileId
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $_SESSION['success'] = 'Log file uploaded and processed successfully.';
        } catch (\Exception $e) {
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Failed to process file: ' . $e->getMessage()
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(500);
            }
            $_SESSION['error'] = 'Failed to process file: ' . $e->getMessage();
        }

        return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
    }

    public function startScan(Request $request, Response $response, array $args): Response
    {
        $id = $args['id']; // Project ID
        $tenantId = $request->getAttribute('tenant_id');
        $data = $request->getParsedBody();
        $reportPlanId = $data['report_plan_id'] ?? null;
        $fileIds = $data['file_ids'] ?? [];

        if (empty($fileIds)) {
            $_SESSION['error'] = 'Please select at least one forensic log file to scan.';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        if (!$reportPlanId) {
            $_SESSION['error'] = 'No forensic package selected.';
            return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
        }

        try {
            $jobId = $this->scanService->queueScan($tenantId, $id, $fileIds, $reportPlanId);

            // Fetch details for logging
            $stmt = $this->pdo->prepare("SELECT name FROM projects WHERE id = :pid");
            $stmt->execute(['pid' => $id]);
            $projectName = $stmt->fetchColumn() ?: 'Unknown';

            $plan = $this->reportPlanService->getPlan($reportPlanId);

            $this->logAction($request, 'scan_queued', 'scan_jobs', $jobId, [
                'project_name' => $projectName,
                'package'      => $plan['name'] ?? 'Custom',
                'file_ids'     => $fileIds,
                'ai_model'     => $plan['ai_model'] ?? 'Unknown'
            ]);

            
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode([
                    'success' => true,
                    'job_id' => $jobId,
                    'debug_file_ids' => $fileIds,
                    'message' => 'Scan job queued successfully.'
                ]));
                return $response->withHeader('Content-Type', 'application/json');
            }

            $_SESSION['success'] = 'Scan job queued successfully. Analysis is running in background.';
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, "INSUFFICIENT_TOKENS:")) {
                $parts = explode(':', $msg);
                $balance = number_format((int)($parts[1] ?? 0));
                $required = number_format((int)($parts[2] ?? 0));
                $msg = "Insufficient tokens. You need at least {$required} tokens for this scan. Your current balance is {$balance}.";
            }

            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode([
                    'success' => false,
                    'message' => 'Failed to queue scan: ' . $msg
                ]));
                return $response->withHeader('Content-Type', 'application/json')->withStatus(400);
            }
            $_SESSION['error'] = 'Failed to queue scan: ' . $msg;
        }

        return $response->withHeader('Location', $this->basePath . "/projects/view/{$id}")->withStatus(302);
    }

    public function viewReport(Request $request, Response $response, array $args): Response
    {
        $jobId = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch Job
        $stmt = $this->pdo->prepare("SELECT * FROM scan_jobs WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $jobId, 'tid' => $tenantId]);
        $job = $stmt->fetch();

        if (!$job) {
            return $response->withHeader('Location', $this->basePath . '/dashboard')->withStatus(302);
        }

        // Fetch Findings
        $stmt = $this->pdo->prepare("SELECT * FROM scan_findings WHERE scan_job_id = :jid ORDER BY severity ASC, sort_order ASC");
        $stmt->execute(['jid' => $jobId]);
        $findings = $stmt->fetchAll();

        // Project Info
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :pid");
        $stmt->execute(['pid' => $job['project_id']]);
        $project = $stmt->fetch();

        $body = $this->view->render('tenant/report.twig', [
            'job' => $job,
            'findings' => $findings,
            'project' => $project,
            'active_page' => 'scans'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function getPromptData(Request $request, Response $response, array $args): Response
    {
        $fileId = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // 1. Try to find the latest successful scan job that included this file
        $stmt = $this->pdo->prepare("
            SELECT result_input_payload, scan_level, id
            FROM scan_jobs 
            WHERE :fid = ANY(debug_file_ids) 
              AND tenant_id = :tid 
              AND status = 'completed'
            ORDER BY created_at DESC 
            LIMIT 1
        ");
        $stmt->execute(['fid' => $fileId, 'tid' => $tenantId]);
        $scan = $stmt->fetch();

        if ($scan && !empty($scan['result_input_payload'])) {
            $payloadData = json_decode($scan['result_input_payload'], true);
            $displayData = is_array($payloadData) && isset($payloadData['raw']) ? $payloadData['raw'] : $scan['result_input_payload'];

            $response->getBody()->write(json_encode([
                'success' => true,
                'title' => 'AI Analysis Prompt (' . strtoupper($scan['scan_level']) . ')',
                'data' => $displayData,
                'type' => 'prompt'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        // 2. Fallback to raw extraction data if no scan found
        $stmt = $this->pdo->prepare("SELECT extraction_data FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $fileId, 'tid' => $tenantId]);
        $data = $stmt->fetchColumn();

        if ($data) {
            $response->getBody()->write(json_encode([
                'success' => true,
                'title' => 'Raw Technical Metadata',
                'data' => $data,
                'type' => 'raw'
            ]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        $response->getBody()->write(json_encode(['success' => false, 'message' => 'No diagnostic data available.']));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
    }

    public function getScanPromptData(Request $request, Response $response, array $args): Response
    {
        $jobId = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("
            SELECT result_input_payload, scan_level, project_id
            FROM scan_jobs 
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute(['id' => $jobId, 'tid' => $tenantId]);
        $scan = $stmt->fetch();

        if (!$scan || empty($scan['result_input_payload'])) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'AI Context Payload not found or not yet generated.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'title' => 'Full AI Context Trace (' . strtoupper($scan['package_name'] ?? $scan['scan_level']) . ')',
            'data' => $scan['result_input_payload'],
            'type' => 'prompt'
        ]));
        
        return $response->withHeader('Content-Type', 'application/json');
    }

    public function viewHardwareReport(Request $request, Response $response, array $args): Response
    {
        $fileId = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch File Info
        $stmt = $this->pdo->prepare("SELECT * FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $fileId, 'tid' => $tenantId]);
        $file = $stmt->fetch();

        if (!$file) {
            return $response->withHeader('Location', $this->basePath . '/projects')->withStatus(302);
        }

        // Fetch Project Info
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :pid");
        $stmt->execute(['pid' => $file['project_id']]);
        $project = $stmt->fetch();

        // Decode Data
        $data = json_decode($file['extraction_data'] ?: '{}', true);

        // Fetch Scan History (paginated)
        $page = (int)($request->getQueryParams()['page'] ?? 1);
        $limit = 5;
        $offset = ($page - 1) * $limit;

        $stmt = $this->pdo->prepare("
            SELECT s.*, p.name as package_name 
            FROM scan_jobs s
            LEFT JOIN report_plans p ON s.report_plan_id = p.id
            WHERE :fid = ANY(s.debug_file_ids) 
            AND s.tenant_id = :tid 
            AND s.completed_at > NOW() - INTERVAL '1 year'
            ORDER BY s.completed_at DESC 
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':fid', $fileId);
        $stmt->bindValue(':tid', $tenantId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $scans = $stmt->fetchAll();

        // Total scans count for pagination
        $stmt = $this->pdo->prepare("
            SELECT COUNT(*) FROM scan_jobs 
            WHERE :fid = ANY(debug_file_ids) 
            AND tenant_id = :tid 
            AND completed_at > NOW() - INTERVAL '1 year'
        ");
        $stmt->execute(['fid' => $fileId, 'tid' => $tenantId]);
        $totalScans = $stmt->fetchColumn();
        $totalPages = ceil($totalScans / $limit);

        $body = $this->view->render('tenant/hardware_report.twig', [
            'file' => $file,
            'project' => $project,
            'data' => $data,
            'scans' => $scans,
            'pagination' => [
                'current' => $page,
                'total' => $totalPages,
                'count' => $totalScans
            ],
            'active_page' => 'projects'
        ]);

        $response->getBody()->write($body);
        return $response;
    }

    public function downloadRawData(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // Fetch extracted data and original filename
        $stmt = $this->pdo->prepare("SELECT extraction_data, original_filename FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $file = $stmt->fetch();

        if (!$file) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'Data not found or access denied.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $data = $file['extraction_data'] ?: '{}';
        $filename = 'raw_extraction_' . pathinfo($file['original_filename'], PATHINFO_FILENAME) . '.json';

        $response->getBody()->write($data);
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withHeader('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    public function deleteLogFile(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        // 1. Verify ownership and get file info
        $stmt = $this->pdo->prepare("SELECT id, storage_path FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $file = $stmt->fetch();

        if (!$file) {
            $response->getBody()->write(json_encode(['success' => false, 'message' => 'File not found or access denied.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        // 2. Physical Cleanup
        $this->fileService->deleteProjectFile($id, $file['storage_path']);

        // 3. Database Cleanup
        $stmt = $this->pdo->prepare("DELETE FROM debug_files WHERE id = :id");
        $stmt->execute(['id' => $id]);

        $response->getBody()->write(json_encode(['success' => true, 'message' => 'Diagnostic log deleted successfully.']));

        // Logging
        $this->logAction($request, 'file_deleted', 'debug_files', $id, [
            'storage_path' => $file['storage_path']
        ]);

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getScanStatus(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("
            SELECT id, status, progress_percent, progress_stage, debug_file_ids, result_summary, checkpoints
            FROM scan_jobs 
            WHERE id = :id AND tenant_id = :tid
        ");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);
        $job = $stmt->fetch();

        if (!$job) {
            return $response->withStatus(404);
        }

        // Parse PostgreSQL array string to PHP array for JSON response
        if (isset($job['debug_file_ids']) && is_string($job['debug_file_ids'])) {
            $job['debug_file_ids'] = explode(',', trim($job['debug_file_ids'], '{}'));
        }

        $response->getBody()->write(json_encode($job));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function deleteScan(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("DELETE FROM scan_jobs WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $id, 'tid' => $tenantId]);

        if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
            $response->getBody()->write(json_encode(['success' => true, 'message' => 'Analysis record deleted.']));
            return $response->withHeader('Content-Type', 'application/json');
        }

        return $response->withHeader('Location', $this->basePath . '/dashboard')->withStatus(302);
    }

    public function transactions(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("
            SELECT * FROM audit_log 
            WHERE tenant_id = :tid 
              AND action IN ('tokens_allocated', 'tokens_deducted', 'tokens_requested', 'tokens_redeemed')
            ORDER BY created_at DESC
        ");
        $stmt->execute(['tid' => $tenantId]);
        $logs = $stmt->fetchAll();

        $body = $this->view->render('tenant/transactions.twig', [
            'logs' => $logs,
            'active_page' => 'transactions'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function requestTokens(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $data = $request->getParsedBody();
        $blocks = (int)($data['blocks'] ?? 1);
        $amount = $blocks * 20000;

        if ($amount <= 0) {
            $_SESSION['error'] = "Invalid token amount requested.";
            return $response->withHeader('Location', $this->basePath . '/dashboard')->withStatus(302);
        }

        try {
            $code = bin2hex(random_bytes(32));
            
            // 1. Create redemption record
            $stmt = $this->pdo->prepare("
                INSERT INTO token_redemptions (tenant_id, amount, code, status, expires_at)
                VALUES (:tid, :amount, :code, 'pending', NOW() + INTERVAL '7 days')
            ");
            $stmt->execute([
                'tid' => $tenantId,
                'amount' => $amount,
                'code' => $code
            ]);

            // 2. Audit log
            $stmt = $this->pdo->prepare("
                INSERT INTO audit_log (tenant_id, action, details)
                VALUES (:tid, 'tokens_requested', :details)
            ");
            $stmt->execute([
                'tid' => $tenantId,
                'details' => json_encode(['amount' => $amount, 'blocks' => $blocks])
            ]);

            // 3. Send magic link to Admin
            $stmt = $this->pdo->query("SELECT reporting_email FROM system_settings LIMIT 1");
            $reportingEmail = $stmt->fetchColumn() ?: 'shaheer@activelk.com';

            // Robust URL construction
            $appUrl = rtrim(getenv('APP_URL') ?: 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/');
            $cleanBasePath = '/' . trim($this->basePath, '/');
            $redeemUrl = $appUrl . ($cleanBasePath === '/' ? '' : $cleanBasePath) . "/redeem/" . $code;

            $subject = "Token Purchase Request - " . ($_SESSION['name'] ?? 'Tenant');
            $body = "
                <div style='font-family: sans-serif; line-height: 1.6; color: #333;'>
                    <h2>Token Purchase Request</h2>
                    <p>A tenant has requested additional AI tokens for forensic analysis.</p>
                    <hr style='border: 0; border-top: 1px solid #eee;'>
                    <p><strong>Tenant:</strong> " . ($_SESSION['name'] ?? 'N/A') . "</p>
                    <p><strong>Amount:</strong> " . number_format($amount) . " Tokens ({$blocks} blocks of 20k)</p>
                    <p><strong>Date:</strong> " . date('Y-m-d H:i:s') . "</p>
                    <br>
                    <p>To approve and grant these tokens, click the magic link below:</p>
                    <p><a href='{$redeemUrl}' style='display: inline-block; padding: 12px 24px; background: #007bff; color: white; text-decoration: none; border-radius: 5px; font-weight: bold;'>Approve & Grant Tokens</a></p>
                    <br>
                    <p style='font-size: 12px; color: #666;'>If the button doesn't work, copy and paste this URL:<br> {$redeemUrl}</p>
                </div>
            ";

            $success = $this->mailService->send($reportingEmail, $subject, $body);

            if ($success) {
                $_SESSION['success'] = "Token purchase request for " . number_format($amount) . " tokens has been sent to <strong>{$reportingEmail}</strong> for approval.";
            } else {
                $_SESSION['error'] = "Token request logged, but the notification email failed to send to <strong>{$reportingEmail}</strong>. Please check your SMTP settings.";
            }
        } catch (\Exception $e) {
            $_SESSION['error'] = "Failed to request tokens: " . $e->getMessage();
        }

        return $response->withHeader('Location', $this->basePath . '/dashboard')->withStatus(302);
    }

    public function audit(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $queryParams = $request->getQueryParams();
        
        // Pagination
        $page = isset($queryParams['page']) ? (int)$queryParams['page'] : 1;
        $limit = 20;
        $offset = ($page - 1) * $limit;

        // Search
        $search = !empty($queryParams['q']) ? $queryParams['q'] : null;

        // Sorting
        $allowedSorts = ['created_at', 'action', 'performer_name'];
        $sort = in_array($queryParams['sort'] ?? '', $allowedSorts) ? $queryParams['sort'] : 'created_at';
        $order = (strtoupper($queryParams['order'] ?? '') === 'ASC') ? 'ASC' : 'DESC';

        // Defaults for date/category
        $dateFrom = !empty($queryParams['from']) ? $queryParams['from'] : date('Y-m-d', strtotime('-30 days'));
        $dateTo = !empty($queryParams['to']) ? $queryParams['to'] : date('Y-m-d');
        $category = !empty($queryParams['category']) ? $queryParams['category'] : 'all';

        // Fetch Tenant Info (Self)
        $stmt = $this->pdo->prepare("SELECT tokens_available FROM users WHERE id = :id");
        $stmt->execute(['id' => $tenantId]);
        $tokensAvailable = $stmt->fetchColumn() ?: 0;

        // Build log query
        $whereSql = "WHERE a.tenant_id = :tid AND a.created_at >= :from AND a.created_at <= :to";
        $params = ['tid' => $tenantId, 'from' => $dateFrom . ' 00:00:00', 'to' => $dateTo . ' 23:59:59'];

        if ($category === 'financial') {
            $whereSql .= " AND a.action IN ('tokens_requested', 'tokens_redeemed', 'tokens_allocated', 'tokens_deducted')";
        } elseif ($category === 'scans') {
            $whereSql .= " AND a.action IN ('scan_queued', 'scan_started', 'scan_completed', 'scan_failed', 'tokens_deducted')";
        } elseif ($category === 'sessions') {
            $whereSql .= " AND a.action IN ('login', 'logout', 'login_failed')";
        } elseif ($category === 'files') {
            $whereSql .= " AND a.action IN ('file_uploaded', 'file_deleted')";
        }

        if ($search) {
            $whereSql .= " AND (a.action ILIKE :q OR a.details ILIKE :q OR u.display_name ILIKE :q)";
            $params['q'] = "%$search%";
        }

        // 1. Get Total Count
        $countSql = "SELECT COUNT(*) FROM audit_log a LEFT JOIN users u ON a.user_id = u.id $whereSql";
        $stmt = $this->pdo->prepare($countSql);
        $stmt->execute($params);
        $totalLogs = $stmt->fetchColumn();
        $totalPages = ceil($totalLogs / $limit);

        // 2. Fetch Records
        $orderBy = $sort === 'performer_name' ? 'u.display_name' : "a.$sort";
        
        $sql = "
            SELECT a.*, u.display_name as performer_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            $whereSql
            ORDER BY $orderBy $order 
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) $stmt->bindValue($k, $v);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll();

        $body = $this->view->render('tenant/audit.twig', [
            'logs' => $logs,
            'tokens_available' => $tokensAvailable,
            'totalPages' => $totalPages,
            'currentPage' => $page,
            'search' => $search,
            'sort' => $sort,
            'order' => $order,
            'filters' => [
                'from' => $dateFrom,
                'to' => $dateTo,
                'category' => $category
            ],
            'active_page' => 'audit'
        ]);
        
        $response->getBody()->write($body);
        return $response;
    }

    public function exportAudit(Request $request, Response $response): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $queryParams = $request->getQueryParams();
        
        $dateFrom = !empty($queryParams['from']) ? $queryParams['from'] : date('Y-m-d', strtotime('-365 days'));
        $dateTo = !empty($queryParams['to']) ? $queryParams['to'] : date('Y-m-d');

        $stmt = $this->pdo->prepare("
            SELECT a.*, u.display_name as performer_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            WHERE a.tenant_id = :tid 
            AND a.created_at >= :from 
            AND a.created_at <= :to
            ORDER BY a.created_at DESC
        ");
        $stmt->execute([
            'tid' => $tenantId, 
            'from' => $dateFrom . ' 00:00:00', 
            'to' => $dateTo . ' 23:59:59'
        ]);
        $logs = $stmt->fetchAll();

        $stream = fopen('php://memory', 'w+');
        // UTF-8 BOM for Excel
        fprintf($stream, chr(0xEF).chr(0xBB).chr(0xBF));
        
        fputcsv($stream, [
            'Timestamp (UTC)', 
            'Action', 
            'Performer', 
            'Project/Resource', 
            'Level', 
            'Tokens Change', 
            'Before Balance', 
            'After Balance', 
            'IP Address', 
            'Details'
        ]);

        foreach ($logs as $log) {
            $details = json_decode($log['details'], true) ?: [];
            
            // Extract tokens change
            $tokenChange = $details['amount_deducted'] ?? ($details['amount'] ?? ($details['used'] ?? 0));
            if (in_array($log['action'], ['tokens_deducted', 'scan_completed'])) {
                $tokenChange = '-' . $tokenChange;
            } elseif (in_array($log['action'], ['tokens_allocated', 'tokens_redeemed'])) {
                $tokenChange = '+' . $tokenChange;
            }

            fputcsv($stream, [
                $log['created_at'],
                strtoupper(str_replace('_', ' ', $log['action'])),
                $log['performer_name'] ?? 'System',
                $details['project_name'] ?? ($log['resource_type'] ? $log['resource_type'] . ': ' . $log['resource_id'] : 'N/A'),
                $details['package_name'] ?? ($details['scan_level'] ?? 'N/A'),
                $tokenChange,
                $details['before'] ?? 'N/A',
                $details['after'] ?? 'N/A',
                $log['ip_address'],
                $log['details']
            ]);
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        $filename = "ForensicAudit_" . date('Ymd') . ".csv";

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"$filename\"");
    }

    private function logAction(Request $request, string $action, ?string $resourceType = null, ?string $resourceId = null, array $details = []): void
    {
        $userId = $request->getAttribute('user_id') ?? ($_SESSION['user_id'] ?? null);
        $tenantId = $request->getAttribute('tenant_id') ?? ($_SESSION['tenant_id'] ?? null);
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ua = $request->getServerParams()['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (user_id, tenant_id, action, resource_type, resource_id, details, ip_address, user_agent)
            VALUES (:uid, :tid, :act, :rt, :rid, :details, :ip, :ua)
        ");
        
        $stmt->execute([
            'uid' => $userId,
            'tid' => $tenantId,
            'act' => $action,
            'rt' => $resourceType,
            'rid' => $resourceId,
            'details' => json_encode($details),
            'ip' => $ip,
            'ua' => $ua
        ]);
    }

    public function resolveSerialMismatch(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $tenantId = $request->getAttribute('tenant_id');
        $fileId = $data['file_id'] ?? null;
        $action = $data['action'] ?? null;
        $extractedSerial = $data['extracted_serial'] ?? null;

        if (!$fileId) {
            return $response->withStatus(400);
        }

        // Fetch temp file info from debug_files (it's already there but linked to old project)
        $stmt = $this->pdo->prepare("SELECT * FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $fileId, 'tid' => $tenantId]);
        $fileRecord = $stmt->fetch();

        if (!$fileRecord) {
            return $response->withStatus(404);
        }

        $targetProjectId = null;

        if ($action === 'create_new') {
            // Extract metadata from file record
            $parsedData = json_decode($fileRecord['extraction_data'] ?: '{}', true);
            $hw = $parsedData['hardware'] ?? [];
            $ver = $parsedData['version'] ?? [];
            
            // Create New Project with full extracted metadata
            $stmt = $this->pdo->prepare("
                INSERT INTO projects (
                    tenant_id, name, serial_number, model, dsm_version, ram_gb, cpu_model, physical_location
                ) VALUES (
                    :tid, :name, :sn, :model, :dsm, :ram, :cpu, :loc
                ) RETURNING id
            ");
            $stmt->execute([
                'tid' => $tenantId,
                'name' => "Project " . ($extractedSerial ?: date('Ymd-His')),
                'sn' => $extractedSerial,
                'model' => $hw['model'] ?? null,
                'dsm' => $ver['product'] ?? null,
                'ram' => $hw['ram_gb'] ?? null,
                'cpu' => $hw['cpu_model'] ?? null,
                'loc' => $hw['location'] ?? null
            ]);
            $targetProjectId = $stmt->fetchColumn();
        } elseif ($action === 'move_to_existing') {
            $targetProjectId = $data['target_project_id'] ?? null;
        } elseif ($action === 'ignore') {
            // Just keep current link (nothing to change in debug_files)
            $targetProjectId = $fileRecord['project_id'];
        } else { // cancel
            $this->fileService->deleteProjectFile($fileId, $fileRecord['storage_path']);
            $stmt = $this->pdo->prepare("DELETE FROM debug_files WHERE id = :id");
            $stmt->execute(['id' => $fileId]);
            
            return $response->withHeader('Content-Type', 'application/json');
        }

        if ($targetProjectId && $targetProjectId !== $fileRecord['project_id']) {
            $stmt = $this->pdo->prepare("UPDATE debug_files SET project_id = :pid WHERE id = :id");
            $stmt->execute(['pid' => $targetProjectId, 'id' => $fileId]);
        }

        $response->getBody()->write(json_encode([
            'success' => true,
            'redirect' => $this->basePath . '/projects/view/' . $targetProjectId
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }
}

