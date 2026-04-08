<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileService;
use App\Services\ParseService;
use App\Services\ScanService;
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

    public function __construct(Environment $view, PDO $pdo, FileService $fileService, ScanService $scanService, ParseService $parseService)
    {
        $this->view = $view;
        $this->pdo = $pdo;
        $this->fileService = $fileService;
        $this->scanService = $scanService;
        $this->parseService = $parseService;
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
        $tokensAvailable = $stmt->fetchColumn();

        // Recent Scans
        $stmt = $this->pdo->prepare("
            SELECT s.id, p.name as project_name, s.status, s.health_score, s.completed_at 
            FROM scan_jobs s
            JOIN projects p ON s.project_id = p.id
            WHERE s.tenant_id = :tid
            ORDER BY s.created_at DESC
            LIMIT 5
        ");
        $stmt->execute(['tid' => $tenantId]);
        $recentScans = $stmt->fetchAll();

        $body = $this->view->render('tenant/dashboard.twig', [
            'project_count' => $projectCount,
            'scan_count' => $scanCount,
            'tokens_available' => $tokensAvailable,
            'recent_scans' => $recentScans,
            'user_name' => $_SESSION['name'] ?? 'User',
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

        return $response->withHeader('Location', '/projects')->withStatus(302);
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
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        // Fetch Files
        $stmt = $this->pdo->prepare("SELECT * FROM debug_files WHERE project_id = :pid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $id]);
        $files = $stmt->fetchAll();

        // Fetch Scans
        $stmt = $this->pdo->prepare("SELECT * FROM scan_jobs WHERE project_id = :pid ORDER BY created_at DESC");
        $stmt->execute(['pid' => $id]);
        $scans = $stmt->fetchAll();

        $body = $this->view->render('tenant/project_view.twig', [
            'project' => $project,
            'files' => $files,
            'scans' => $scans,
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
            return $response->withHeader('Location', "/projects/view/{$id}")->withStatus(302);
        }

        // Validate extension
        $filename = $file->getClientFilename();
        if (!str_ends_with(strtolower($filename), '.dat') && !str_ends_with(strtolower($filename), '.zip')) {
            $_SESSION['error'] = 'Invalid file format. Please upload a .dat or .zip file.';
            return $response->withHeader('Location', "/projects/view/{$id}")->withStatus(302);
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
                    updated_at = NOW()
                WHERE id = :pid
            ");
            $stmt->execute([
                'model' => $hw['model'] ?? null,
                'serial' => $hw['serial'] ?? null,
                'dsm' => $ver['product'] ?? null,
                'ram' => $hw['ram_gb'] ?? null,
                'cpu' => $hw['cpu_model'] ?? null,
                'pid' => $id
            ]);

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

        return $response->withHeader('Location', "/projects/view/{$id}")->withStatus(302);
    }

    public function startScan(Request $request, Response $response, array $args): Response
    {
        $id = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');
        $data = $request->getParsedBody();
        $level = $data['level'] ?? 'level1';
        $fileIds = $data['file_ids'] ?? [];

        if (empty($fileIds)) {
            $_SESSION['error'] = 'Please select at least one log file to scan.';
            return $response->withHeader('Location', "/projects/view/{$id}")->withStatus(302);
        }

        try {
            // Get system model setting
            $stmt = $this->pdo->query("SELECT level1_model, level2_model FROM system_settings LIMIT 1");
            $settings = $stmt->fetch();
            $model = ($level === 'level1') ? $settings['level1_model'] : $settings['level2_model'];

            $this->scanService->queueScan($tenantId, $id, $fileIds, $level, $model);
            
            $_SESSION['success'] = 'Scan job queued successfully. Analysis is running in background.';
        } catch (\Exception $e) {
            $_SESSION['error'] = 'Failed to queue scan: ' . $e->getMessage();
        }

        return $response->withHeader('Location', "/projects/view/{$id}")->withStatus(302);
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
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
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

    public function viewRawData(Request $request, Response $response, array $args): Response
    {
        $fileId = $args['id'];
        $tenantId = $request->getAttribute('tenant_id');

        $stmt = $this->pdo->prepare("SELECT extraction_data FROM debug_files WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $fileId, 'tid' => $tenantId]);
        $data = $stmt->fetchColumn();

        if (!$data) {
            $response->getBody()->write(json_encode(['error' => 'No extraction data available or file not found.']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        }

        $response->getBody()->write($data);
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
            return $response->withHeader('Location', '/projects')->withStatus(302);
        }

        // Fetch Project Info
        $stmt = $this->pdo->prepare("SELECT * FROM projects WHERE id = :pid");
        $stmt->execute(['pid' => $file['project_id']]);
        $project = $stmt->fetch();

        // Decode Data
        $data = json_decode($file['extraction_data'] ?: '{}', true);

        $body = $this->view->render('tenant/hardware_report.twig', [
            'file' => $file,
            'project' => $project,
            'data' => $data,
            'active_page' => 'projects'
        ]);
        $response->getBody()->write($body);
        return $response;
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
        return $response->withHeader('Content-Type', 'application/json');
    }
}
