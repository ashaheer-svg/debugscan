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

/**
 * Authentication & Authorization Middleware with RLS Context Setup
 *
 * PURPOSE:
 * Validate user session, verify account status, and configure PostgreSQL
 * row-level security (RLS) context for data isolation per tenant.
 *
 * EXECUTION FLOW:
 * 1. Session validation: Check user_id in session, redirect to login if absent
 * 2. User verification: Verify user exists in database and account is active
 * 3. Tenant mapping check: Confirm tenant association hasn't changed (security check)
 * 4. RLS context setup: Call Database::setTenantContext() to configure row-level security
 * 5. Attribute injection: Store user_id, tenant_id, role in request for downstream handlers
 *
 * SECURITY MECHANISMS:
 * - Session-based authentication (user_id in $_SESSION)
 * - User existence verification (prevents access with deleted accounts)
 * - Tenant mapping validation (prevents privilege escalation between tenants)
 * - Admin exception: Admins can access all tenants (no tenant_id constraint)
 * - Separate API/UI handling: API returns 401, UI redirects to login
 *
 * SESSION STRUCTURE:
 * - user_id: UUID of authenticated user
 * - tenant_id: UUID of tenant owning this user (null for admins)
 * - role: Either 'admin' or 'tenant'
 *
 * INTEGRATION:
 * - Registered first in Slim middleware stack (runs on every request)
 * - Database RLS context depends on this middleware setting tenant_id
 * - Called before all route handlers and other middleware
 * - Does NOT validate CSRF tokens (CsrfMiddleware handles that)
 *
 * ERROR RESPONSES:
 * - Missing session: Redirect to /login (UI) or 401 (API)
 * - Deleted user/inactive account: Redirect to /login?error=... (session destroyed)
 * - Tenant mismatch: Redirect to /login?error=... (possible account compromise)
 *
 * @package App\Middleware
 */
class AuthMiddleware implements MiddlewareInterface
{
    private PDO $pdo;
    private string $basePath;

    /**
     * Constructor: Dependency injection of database and base path
     *
     * @param PDO    $pdo      Database connection (from DI container)
     * @param string $basePath Application base path (for redirect URLs)
     */
    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = $basePath;
    }

    /**
     * PSR-15 middleware process handler: Authenticate and authorize request
     *
     * EXECUTION STEPS:
     * 1. Start session if not already started
     * 2. Check for user_id in session (if absent, reject or redirect)
     * 3. Verify user exists and is active in database
     * 4. Validate tenant mapping hasn't changed (security check)
     * 5. Call Database::setTenantContext() for PostgreSQL RLS enforcement
     * 6. Store user context in request attributes for downstream handlers
     * 7. Call next handler (or return error response)
     *
     * SECURITY NOTES:
     * - Session fixation protection: Verify database state matches session
     * - Tenant isolation: Reject if tenant_id in session != database expectation
     * - Admin exception: Admins skip tenant validation (null tenant_id allowed)
     * - Account deactivation: Immediately rejected even with valid session
     *
     * @param Request $request PSR-7 server request
     * @param Handler $handler Next middleware/route handler in chain
     *
     * @return Response PSR-7 response (either from handler or auth failure response)
     *
     * @throws HttpUnauthorizedException When API request lacks authentication
     */
    public function process(Request $request, Handler $handler): Response
    {
        // Start PHP session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (!isset($_SESSION['user_id'])) {
            // No session: distinguish between API and UI requests for appropriate error response
            $path = $request->getUri()->getPath();
            if (str_starts_with($path, '/api/')) {
                // API clients expect JSON; throw exception (handled by error middleware)
                throw new HttpUnauthorizedException($request, "Unauthorized");
            }

            // UI clients expect redirect to login page
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login')->withStatus(302);
        }

        // === STEP 1: Extract session data ===
        $userId   = $_SESSION['user_id'];
        $tenantId = $_SESSION['tenant_id'] ?? null;
        $role     = $_SESSION['role'] ?? 'tenant';

        // === STEP 2: Verify user still exists and is active ===
        // This prevents access with accounts that were deactivated/deleted after login
        $stmt = $this->pdo->prepare("SELECT status, tenant_id, role, id FROM users WHERE id = :id");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        if (!$user || $user['status'] !== 'active') {
            // User deleted or deactivated: destroy session and redirect to login with error
            session_destroy();
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login?error=Session+expired+or+account+deactivated')->withStatus(302);
        }

        // === STEP 3: Calculate expected tenant ID and validate mapping ===
        // Logic matches LoginController to ensure consistency:
        // - If user.tenant_id is set, use it (user belongs to an explicit tenant)
        // - If role='tenant' and no tenant_id, use user.id as self-owned tenant
        // - Otherwise (admins), tenant_id is null
        $expectedTenantId = $user['tenant_id'] ?: ($user['role'] === 'tenant' ? $user['id'] : null);

        // Verify tenant mapping hasn't changed (prevents moving users between tenants)
        // Admins bypass this check since they operate across all tenants
        if ($role !== 'admin' && $expectedTenantId !== $tenantId) {
            // Mismatch detected: possible session tampering or privilege escalation attempt
            session_destroy();
            $response = new \Slim\Psr7\Response();
            return $response->withHeader('Location', $this->basePath . '/login?error=Security+Conflict:+Tenant+mapping+mismatch')->withStatus(302);
        }

        // === STEP 4: Configure PostgreSQL RLS context ===
        // This sets session variables that PostgreSQL policies reference for row-level filtering
        \App\Database::setTenantContext($this->pdo, $tenantId, $role);

        // === STEP 5: Attach user context to request for handlers ===
        // Downstream handlers access these via $request->getAttribute('user_id'), etc.
        $request = $request->withAttribute('user_id', $userId)
                          ->withAttribute('tenant_id', $tenantId)
                          ->withAttribute('role', $role);

        // Call next middleware/handler in the chain
        return $handler->handle($request);
    }
}
