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
            'active_page' => 'admin_tenants',
            'error' => $_SESSION['error'] ?? null,
            'success' => $_SESSION['success'] ?? null
        ]);
        
        unset($_SESSION['error']);
        unset($_SESSION['success']);
        
        $response->getBody()->write($body);
        return $response;
    }

    public function createTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $orgName = trim($data['org_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $tokens = (int)($data['tokens'] ?? 500000);

        if (empty($orgName) || empty($email) || empty($password)) {
            $_SESSION['error'] = 'All fields are required to provision a tenant.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        try {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $this->pdo->prepare("
                INSERT INTO users (email, password_hash, display_name, role, status, tokens_available)
                VALUES (:email, :pass, :name, 'tenant', 'active', :tokens)
            ");
            $stmt->execute([
                'email' => $email,
                'pass' => $hashedPassword,
                'name' => $orgName,
                'tokens' => $tokens
            ]);
            
            $_SESSION['success'] = "Tenant '{$orgName}' has been provisioned successfully.";
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') { // Unique violation
                $_SESSION['error'] = 'A user with that email already exists.';
            } else {
                $_SESSION['error'] = 'Failed to create tenant: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
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
