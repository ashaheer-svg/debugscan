<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Database;
use App\Models\User;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpUnauthorizedException;

class AuthMiddleware implements MiddlewareInterface
{
    private PDO $pdo;
    private string $basePath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = $basePath;
    }

    public function process(Request $request, Handler $handler): Response
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            // Check if this is an API request or a UI request
            $path = $request->getUri()->getPath();
            if (str_starts_with($path, '/api/')) {
                throw new HttpUnauthorizedException($request, "Unauthorized");
            }
            
            // Redirect to login for UI requests
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login')->withStatus(302);
        }

        // 1. Authenticity Check & Context Verification
        $userId   = $_SESSION['user_id'];
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $role     = $_SESSION['role'] ?? 'tenant';

        // Verify user still exists and is active in DB
        $stmt = $this->pdo->prepare("SELECT status, tenant_id FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            session_destroy();
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login?error=Session+expired+or+account+deactivated')->withStatus(302);
        }

        // Verify tenant mapping hasn't changed (unless admin)
        if ($role !== 'admin' && $user['tenant_id'] !== $tenantId) {
            session_destroy();
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login?error=Security+Conflict:+Tenant+mapping+mismatch')->withStatus(302);
        }

        // 2. Set Database RLS Context
        \App\Database::setTenantContext($this->pdo, $tenantId, $role);

        // 3. Populate Request Attributes
        $request = $request->withAttribute('user_id', $userId)
                          ->withAttribute('tenant_id', $tenantId)
                          ->withAttribute('role', $role);

        return $handler->handle($request);
    }
}
