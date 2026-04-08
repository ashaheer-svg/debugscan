<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AiService;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Twig\Environment;

class AdminController
{
    private Environment $view;
    private PDO $pdo;
    private AiService $aiService;

    public function __construct(Environment $view, PDO $pdo, AiService $aiService)
    {
        $this->view = $view;
        $this->pdo = $pdo;
        $this->aiService = $aiService;
    }

    public function dashboard(Request $request, Response $response): Response
    {
        // Global System KPIs
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM users WHERE role = 'tenant'");
        $tenantCount = $stmt->fetchColumn();

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM scan_jobs");
        $totalScans = $stmt->fetchColumn();

        $stmt = $this->pdo->query("SELECT SUM(ai_input_tokens_used + ai_output_tokens_used) FROM scan_jobs");
        $totalTokensUsed = $stmt->fetchColumn() ?? 0;

        // Recent System Activity
        $stmt = $this->pdo->query("
            SELECT a.action, a.created_at, u.display_name as user_name, a.details
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 10
        ");
        $recentActivity = $stmt->fetchAll();

        $body = $this->view->render('admin/dashboard.twig', [
            'tenant_count' => $tenantCount,
            'total_scans' => $totalScans,
            'tokens_used' => $totalTokensUsed,
            'recent_activity' => $recentActivity,
            'active_page' => 'admin_dash'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function tenants(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM users WHERE role = 'tenant' ORDER BY created_at DESC");
        $tenants = $stmt->fetchAll();

        $body = $this->view->render('admin/tenants.twig', [
            'tenants' => $tenants,
            'active_page' => 'admin_tenants'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function logs(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("
            SELECT a.*, u.display_name as user_name 
            FROM audit_log a 
            LEFT JOIN users u ON a.user_id = u.id 
            ORDER BY a.created_at DESC 
            LIMIT 100
        ");
        $logs = $stmt->fetchAll();

        $body = $this->view->render('admin/logs.twig', [
            'logs' => $logs,
            'active_page' => 'admin_logs'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function settings(Request $request, Response $response): Response
    {
        // Get current system settings
        $stmt = $this->pdo->query("SELECT * FROM system_settings LIMIT 1");
        $settings = $stmt->fetch();

        // Get available models from Groq API
        try {
            $models = $this->aiService->getAvailableModels();
        } catch (\Exception $e) {
            $models = [];
            $error = "Could not fetch models: " . $e->getMessage();
        }

        $body = $this->view->render('admin/settings.twig', [
            'settings' => $settings,
            'models' => $models,
            'error' => $error ?? null,
            'active_page' => 'admin_settings'
        ]);
        $response->getBody()->write($body);
        return $response;
    }

    public function updateSettings(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        
        $stmt = $this->pdo->prepare("
            UPDATE system_settings 
            SET level1_model = :l1, 
                level2_model = :l2, 
                retention_days = :retention,
                updated_at = NOW()
            WHERE id = 1
        ");
        
        $stmt->execute([
            'l1' => $data['level1_model'],
            'l2' => $data['level2_model'],
            'retention' => (int)$data['retention_days'],
        ]);

        return $response->withHeader('Location', '/admin/settings?status=saved')->withStatus(302);
    }
}
