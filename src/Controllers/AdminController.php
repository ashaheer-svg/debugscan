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
        $stmt = $this->pdo->query("
            SELECT 
                u.*, 
                COALESCE(SUM(df.file_size_bytes), 0) as total_storage_bytes,
                COUNT(df.id) as total_entries
            FROM users u
            LEFT JOIN debug_files df ON u.id = df.tenant_id
            WHERE u.role = 'tenant'
            GROUP BY u.id
            ORDER BY u.created_at DESC
        ");
        $tenantsData = $stmt->fetchAll();

        // Format storage strings
        $tenants = array_map(function($t) {
            $bytes = (int)$t['total_storage_bytes'];
            if ($bytes >= 1073741824) {
                $t['storage_formatted'] = number_format($bytes / 1073741824, 2) . ' GB';
            } elseif ($bytes >= 1048576) {
                $t['storage_formatted'] = number_format($bytes / 1048576, 2) . ' MB';
            } else {
                $t['storage_formatted'] = number_format($bytes / 1024, 2) . ' KB';
            }
            return $t;
        }, $tenantsData);

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
            // We use a CTE to insert and set tenant_id to the new id in one go
            $stmt = $this->pdo->prepare("
                WITH new_user AS (
                    INSERT INTO users (email, password_hash, display_name, role, status, tokens_available)
                    VALUES (:email, :pass, :name, 'tenant', 'active', :tokens)
                    RETURNING id
                )
                UPDATE users SET tenant_id = id FROM new_user WHERE users.id = new_user.id RETURNING users.id
            ");
            $stmt->execute([
                'email' => $email,
                'pass' => $hashedPassword,
                'name' => $orgName,
                'tokens' => $tokens
            ]);
            $tenantId = $stmt->fetchColumn();
            
            $this->logAction($request, 'user_created', 'users', $tenantId, [
                'email' => $email,
                'display_name' => $orgName,
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

    public function updateTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $orgName = trim($data['org_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';

        if (!$id || empty($orgName) || empty($email)) {
            $_SESSION['error'] = 'ID, Organization Name, and Email are required.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        try {
            $sql = "UPDATE users SET display_name = :name, email = :email, updated_at = NOW()";
            $params = ['name' => $orgName, 'email' => $email, 'id' => $id];

            if (!empty($password)) {
                $sql .= ", password_hash = :pass";
                $params['pass'] = password_hash($password, PASSWORD_BCRYPT);
            }

            $sql .= " WHERE id = :id AND role = 'tenant'";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $this->logAction($request, 'user_updated', 'users', $id, [
                'email' => $email,
                'display_name' => $orgName,
                'password_changed' => !empty($password)
            ]);

            $_SESSION['success'] = "Tenant '{$orgName}' updated successfully.";
        } catch (\PDOException $e) {
            if ($e->getCode() === '23505') {
                $_SESSION['error'] = 'A user with that email already exists.';
            } else {
                $_SESSION['error'] = 'Failed to update tenant: ' . $e->getMessage();
            }
        }

        return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
    }

    public function toggleTenantStatus(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$id || !in_array($status, ['active', 'inactive'])) {
            $_SESSION['error'] = 'Invalid status toggle request.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        $stmt = $this->pdo->prepare("UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['status' => $status, 'id' => $id]);

        $this->logAction($request, $status === 'active' ? 'user_updated' : 'user_deactivated', 'users', $id, ['new_status' => $status]);

        $_SESSION['success'] = "Tenant status changed to " . ucfirst($status) . ".";
        return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
    }

    public function deleteTenant(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;

        if (!$id) {
            $_SESSION['error'] = 'Tenant ID required for deletion.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['id' => $id]);

        $this->logAction($request, 'user_deleted', 'users', $id);

        $_SESSION['success'] = "Tenant permanently deleted.";
        return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
    }

    public function allocateTokens(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $id = $data['id'] ?? null;
        $amount = (int)($data['amount'] ?? 0);

        if (!$id || $amount <= 0) {
            $_SESSION['error'] = 'Valid amount and Tenant ID required.';
            return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
        }

        $stmt = $this->pdo->prepare("UPDATE users SET tokens_available = tokens_available + :amount, updated_at = NOW() WHERE id = :id AND role = 'tenant'");
        $stmt->execute(['amount' => $amount, 'id' => $id]);

        $this->logAction($request, 'tokens_allocated', 'users', $id, ['amount' => $amount]);

        $_SESSION['success'] = "Allocated {$amount} tokens successfully.";
        return $response->withHeader('Location', '/admin/tenants')->withStatus(302);
    }

    private function logAction(Request $request, string $action, ?string $resourceType = null, ?string $resourceId = null, array $details = []): void
    {
        $userId = $_SESSION['user_id'] ?? null;
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        $ua = $request->getServerParams()['HTTP_USER_AGENT'] ?? null;

        $stmt = $this->pdo->prepare("
            INSERT INTO audit_log (user_id, action, resource_type, resource_id, details, ip_address, user_agent)
            VALUES (:uid, :act, :rt, :rid, :details, :ip, :ua)
        ");
        
        $stmt->execute([
            'uid' => $userId,
            'act' => $action,
            'rt' => $resourceType,
            'rid' => $resourceId,
            'details' => json_encode($details),
            'ip' => $ip,
            'ua' => $ua
        ]);
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
