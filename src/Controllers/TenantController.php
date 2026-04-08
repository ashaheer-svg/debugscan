<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\FileService;
use App\Services\ParseService;
use App\Services\ScanService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class TenantController
{
    private Environment $view;
    private PDO $pdo;
    private FileService $fileService;
    private ScanService $scanService;

    public function __construct(Environment $view, PDO $pdo, FileService $fileService, ScanService $scanService)
    {
        $this->view = $view;
        $this->pdo = $pdo;
        $this->fileService = $fileService;
        $this->scanService = $scanService;
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
}
