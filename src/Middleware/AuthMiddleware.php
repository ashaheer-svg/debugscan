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

        // Set RLS Context
        $userId   = $_SESSION['user_id'];
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $role     = $_SESSION['role'] ?? 'tenant';

        Database::setTenantContext($this->pdo, $tenantId, $role);

        // Add user info to request
        $request = $request->withAttribute('user_id', $userId)
                          ->withAttribute('tenant_id', $tenantId)
                          ->withAttribute('role', $role);

        return $handler->handle($request);
    }
}
