<?php

declare(strict_types=1);

namespace App\DeepDive\Controllers;

use App\DeepDive\Services\JobRepository;
use App\DeepDive\Support\Paths;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Twig\Environment;

/**
 * Thin controller for the DeepDive feature. Every action checks:
 *   1) session user
 *   2) tenants.deepdive_enabled for the tenant
 *   3) tenant owns the project / job
 *
 * All routes live under /deepdive/* — no route in the existing app uses
 * that prefix, so this cannot collide.
 */
final class JobController
{
    public function __construct(
        private readonly PDO         $pdo,
        private readonly Environment $twig,
    ) {}

    public function start(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId, $role] = $this->currentUser();
        if (!$tenantId) return $this->redirect($res, '/login');

        $projectId = (string)($args['projectId'] ?? '');
        if (!$this->validUuid($projectId)) {
            return $this->flashBack($res, 'Invalid project', '/dashboard');
        }

        if (!$this->assertFeatureEnabled($tenantId)) {
            return $this->flashBack($res, 'DeepDive is not enabled for your account', "/projects/view/{$projectId}");
        }

        if (!$this->tenantOwnsProject($tenantId, $projectId, $role)) {
            return $this->flashBack($res, 'Project not found', '/projects');
        }

        $body = (array)$req->getParsedBody();
        $fileIds = $body['debug_file_ids'] ?? [];
        if (!is_array($fileIds)) $fileIds = [$fileIds];
        $fileIds = array_values(array_filter($fileIds, fn($x) => is_string($x) && $this->validUuid($x)));

        if (empty($fileIds)) {
            // Auto-select all files for this project (common case — user
            // clicks "Run Deep Dive" without ticking anything).
            $fileIds = $this->projectDebugFileIds($tenantId, $projectId, $role);
        }

        if (empty($fileIds)) {
            return $this->flashBack($res, 'No debug files available for this project', "/projects/view/{$projectId}");
        }

        $jobs = new JobRepository($this->pdo);

        // Option F: single-active-job guard.
        if ($jobs->tenantHasActiveJob($tenantId)) {
            return $this->flashBack($res, 'A DeepDive is already running; wait for it to finish.', "/projects/view/{$projectId}");
        }

        $debugMode = !empty($body['debug_mode']) && $role === 'admin';
        $jobId = $jobs->enqueue($tenantId, $projectId, $fileIds, $debugMode);

        $_SESSION['flash_success'] = 'DeepDive queued. Live status below.';
        return $this->redirect($res, "/deepdive/view/{$jobId}");
    }

    public function view(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) return $this->redirect($res, '/login');

        $jobId = (string)($args['id'] ?? '');
        if (!$this->validUuid($jobId)) return $this->redirect($res, '/dashboard');

        $jobs = new JobRepository($this->pdo);
        $job  = $jobs->findForTenant($jobId, $tenantId);
        if (!$job) {
            $_SESSION['flash_error'] = 'DeepDive job not found';
            return $this->redirect($res, '/dashboard');
        }

        $steps = [];
        if (!empty($job['steps_json'])) {
            $decoded = json_decode((string)$job['steps_json'], true);
            if (is_array($decoded)) $steps = $decoded;
        }

        $body = $this->twig->render('tenant/deepdive/progress.twig', [
            'job'   => $job,
            'steps' => $steps,
        ]);
        $res->getBody()->write($body);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function status(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) {
            return $res->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $jobId = (string)($args['id'] ?? '');
        if (!$this->validUuid($jobId)) {
            return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $jobs = new JobRepository($this->pdo);
        $job  = $jobs->findForTenant($jobId, $tenantId);
        if (!$job) {
            return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        $steps = [];
        if (!empty($job['steps_json'])) {
            $decoded = json_decode((string)$job['steps_json'], true);
            if (is_array($decoded)) $steps = $decoded;
        }

        $response = [
            'job_id'           => $job['id'],
            'status'           => $job['status'],
            'progress_percent' => (int)$job['progress_percent'],
            'progress_stage'   => $job['progress_stage'],
            'error_message'    => $job['error_message'],
            'steps'            => $steps,
        ];

        $res->getBody()->write(json_encode($response, JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    public function report(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) return $this->redirect($res, '/login');

        $jobId = (string)($args['id'] ?? '');
        if (!$this->validUuid($jobId)) return $this->redirect($res, '/dashboard');

        $jobs = new JobRepository($this->pdo);
        $job  = $jobs->findForTenant($jobId, $tenantId);
        if (!$job || $job['status'] !== 'completed' || empty($job['report_html_path'])) {
            $_SESSION['flash_error'] = 'Report not ready';
            return $this->redirect($res, "/deepdive/view/{$jobId}");
        }

        $html = @file_get_contents((string)$job['report_html_path']);
        if ($html === false) {
            $_SESSION['flash_error'] = 'Report file missing on disk';
            return $this->redirect($res, "/deepdive/view/{$jobId}");
        }
        $res->getBody()->write($html);
        return $res->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    public function download(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) return $this->redirect($res, '/login');

        $jobId = (string)($args['id'] ?? '');
        $format = (string)($args['format'] ?? 'html');
        if (!$this->validUuid($jobId)) return $this->redirect($res, '/dashboard');
        if (!in_array($format, ['html','pdf'], true)) return $this->redirect($res, "/deepdive/view/{$jobId}");

        $jobs = new JobRepository($this->pdo);
        $job  = $jobs->findForTenant($jobId, $tenantId);
        if (!$job || $job['status'] !== 'completed') {
            $_SESSION['flash_error'] = 'Report not ready';
            return $this->redirect($res, "/deepdive/view/{$jobId}");
        }

        $path = $format === 'pdf' ? ($job['report_pdf_path'] ?? null) : ($job['report_html_path'] ?? null);
        if (!$path || !file_exists($path)) {
            $_SESSION['flash_error'] = strtoupper($format) . ' report not available for this run';
            return $this->redirect($res, "/deepdive/view/{$jobId}");
        }

        // Defensive: path must be under storage/deepdive/reports/
        $real = realpath($path);
        $base = realpath(Paths::reportsDir());
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            $_SESSION['flash_error'] = 'Invalid report path';
            return $this->redirect($res, "/deepdive/view/{$jobId}");
        }

        $fname = 'deepdive-' . $jobId . '.' . $format;
        $mime  = $format === 'pdf' ? 'application/pdf' : 'text/html';
        $res->getBody()->write((string)file_get_contents($real));
        return $res
            ->withHeader('Content-Type', $mime)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $fname . '"');
    }

    public function cancel(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) return $this->redirect($res, '/login');

        $jobId = (string)($args['id'] ?? '');
        if (!$this->validUuid($jobId)) return $this->redirect($res, '/dashboard');

        $stmt = $this->pdo->prepare("
            UPDATE deepdive_jobs
            SET status = 'cancelled', completed_at = NOW(), error_message = 'Cancelled by user'
            WHERE id = :id AND tenant_id = :tid AND status IN ('queued','running')
        ");
        $stmt->execute(['id' => $jobId, 'tid' => $tenantId]);

        return $this->redirect($res, "/deepdive/view/{$jobId}");
    }

    public function delete(ServerRequestInterface $req, ResponseInterface $res, array $args): ResponseInterface
    {
        [$tenantId] = $this->currentUser();
        if (!$tenantId) {
            return $res->withStatus(401)->withHeader('Content-Type', 'application/json');
        }

        $jobId = (string)($args['id'] ?? '');
        if (!$this->validUuid($jobId)) {
            return $res->withStatus(400)->withHeader('Content-Type', 'application/json');
        }

        $jobs = new JobRepository($this->pdo);
        $job = $jobs->findForTenant($jobId, $tenantId);
        if (!$job) {
            return $res->withStatus(404)->withHeader('Content-Type', 'application/json');
        }

        // Delete report files if they exist
        try {
            if (!empty($job['report_html_path'])) {
                @unlink($job['report_html_path']);
            }
            if (!empty($job['report_pdf_path'])) {
                @unlink($job['report_pdf_path']);
            }
        } catch (\Throwable $e) {
            // Log but don't fail if files can't be deleted
        }

        // Delete the job from database
        $stmt = $this->pdo->prepare("DELETE FROM deepdive_jobs WHERE id = :id AND tenant_id = :tid");
        $stmt->execute(['id' => $jobId, 'tid' => $tenantId]);

        $res->getBody()->write(json_encode(['success' => true], JSON_UNESCAPED_SLASHES));
        return $res->withHeader('Content-Type', 'application/json; charset=utf-8');
    }

    // -------- helpers --------

    /** @return array{0:?string,1:string} */
    private function currentUser(): array
    {
        if (session_status() === PHP_SESSION_NONE) session_start();
        $tid  = $_SESSION['tenant_id'] ?? null;
        $role = $_SESSION['role'] ?? 'tenant';
        return [is_string($tid) ? $tid : null, $role];
    }

    private function validUuid(string $s): bool
    {
        return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
    }

    private function assertFeatureEnabled(string $tenantId): bool
    {
        $stmt = $this->pdo->prepare("SELECT deepdive_enabled FROM users WHERE id = :id AND role='tenant'");
        $stmt->execute(['id' => $tenantId]);
        return (bool)$stmt->fetchColumn();
    }

    private function tenantOwnsProject(string $tenantId, string $projectId, string $role): bool
    {
        if ($role === 'admin') return true;
        $stmt = $this->pdo->prepare("SELECT 1 FROM projects WHERE id = :pid AND tenant_id = :tid");
        $stmt->execute(['pid' => $projectId, 'tid' => $tenantId]);
        return (bool)$stmt->fetchColumn();
    }

    private function projectDebugFileIds(string $tenantId, string $projectId, string $role): array
    {
        $sql = "SELECT id FROM debug_files WHERE project_id = :pid";
        $params = ['pid' => $projectId];
        if ($role !== 'admin') {
            $sql .= " AND tenant_id = :tid";
            $params['tid'] = $tenantId;
        }
        $sql .= " ORDER BY created_at DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_column($stmt->fetchAll(), 'id');
    }

    private function redirect(ResponseInterface $res, string $to): ResponseInterface
    {
        return $res->withHeader('Location', $to)->withStatus(302);
    }

    private function flashBack(ResponseInterface $res, string $msg, string $to): ResponseInterface
    {
        $_SESSION['flash_error'] = $msg;
        return $this->redirect($res, $to);
    }
}
