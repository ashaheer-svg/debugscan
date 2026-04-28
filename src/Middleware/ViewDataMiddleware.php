<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Twig\Environment;
use PDO;

/**
 * View Data Injection Middleware for Twig Template Globals
 *
 * PURPOSE:
 * Query authenticated user details and tenant metadata, then expose them
 * as Twig global variables for use across all template renders.
 *
 * RESPONSIBILITIES:
 * - Query database for authenticated user details
 * - Populate Twig global variables with user/tenant context
 * - Expose feature flags (DeepDive, etc.) as template variables
 * - Provide timezone list for UI timezone selectors
 * - Handle graceful degradation if columns missing (pre-migration state)
 *
 * TWIG GLOBALS EXPOSED:
 * - user_name: User's display_name from database
 * - tokens_available: API token quota for current user
 * - user_role: 'admin' or 'tenant'
 * - user_timezone: User's preferred timezone (defaults to UTC)
 * - all_timezones: List of PHP-supported timezone identifiers
 * - tenant: Object with tenant.deepdive_enabled feature flag
 * - base_path: Application base path (for subdirectory deployments)
 *
 * GRACEFUL DEGRADATION:
 * - If deepdive_enabled column missing: Set to false (pre-migration state)
 * - If timezone column missing: Default to UTC
 * - Database exceptions caught to prevent template rendering failures
 * - Feature flags default to false if query fails
 *
 * EXECUTION ORDER:
 * - Runs AFTER AuthMiddleware (requires user_id in request attributes)
 * - Runs BEFORE route handlers (populates templates for views)
 * - Skipped if no authenticated user (guest requests)
 *
 * INTEGRATION:
 * - Twig Environment dependency injected from DI container
 * - Database PDO for querying user/tenant metadata
 * - Called for every request to ensure fresh data
 *
 * @package App\Middleware
 */
class ViewDataMiddleware implements MiddlewareInterface
{
    private Environment $twig;
    private PDO $pdo;

    /**
     * Constructor: Dependency injection of Twig and database
     *
     * @param Environment $twig Twig template environment for adding globals
     * @param PDO         $pdo  Database connection for querying user/tenant data
     */
    public function __construct(Environment $twig, PDO $pdo)
    {
        $this->twig = $twig;
        $this->pdo = $pdo;
    }

    /**
     * PSR-15 middleware process: Populate Twig template variables with user/tenant data
     *
     * BEHAVIOR:
     * 1. Extract user_id from request attributes (set by AuthMiddleware)
     * 2. Query database for user details (display_name, tokens, role, timezone)
     * 3. Add user/tenant globals to Twig environment
     * 4. Query and expose DeepDive feature flag as {{ tenant.deepdive_enabled }}
     * 5. Provide full timezone list for UI dropdowns
     * 6. Set base path for subdirectory deployments
     *
     * GRACEFUL DEGRADATION:
     * - If user record not found: Skip user globals (guest request)
     * - If timezone column missing: Default to UTC (pre-migration)
     * - If deepdive_enabled column missing: Set to false, continue (feature flag pre-migration)
     * - If user query fails: Skip to timezone defaults
     *
     * ERROR HANDLING:
     * - User data query wrapped in try/catch to handle missing columns gracefully
     * - DeepDive check separately wrapped (so missing column doesn't break page)
     * - All Throwable types caught (includes Parse errors, Type errors, etc.)
     *
     * @param Request $request PSR-7 request (with user_id attribute from AuthMiddleware)
     * @param Handler $handler  Next middleware/handler in chain
     *
     * @return Response PSR-7 response from handler
     */
    public function process(Request $request, Handler $handler): Response
    {
        $userId = $request->getAttribute('user_id');

        if ($userId) {
            try {
                // === Query user details for sidebar/navbar rendering ===
                $stmt = $this->pdo->prepare("SELECT display_name, tokens_available, role, timezone FROM users WHERE id = :id");
                $stmt->execute(['id' => $userId]);
                $user = $stmt->fetch();

                if ($user) {
                    // Expose user data to all Twig templates as globals
                    $this->twig->addGlobal('user_name', $user['display_name']);
                    $this->twig->addGlobal('tokens_available', $user['tokens_available']);
                    $this->twig->addGlobal('user_role', $user['role']);
                    // Use user's timezone preference, fallback to UTC if null or empty string
                    $this->twig->addGlobal('user_timezone', ($user['timezone'] ?? 'UTC') ?: 'UTC');
                    // Timezone list for dropdown selectors in settings
                    $this->twig->addGlobal('all_timezones', \DateTimeZone::listIdentifiers());
                }

                // === Query and expose DeepDive feature flag ===
                // Wrapped in separate try/catch: if deepdive_enabled column doesn't exist yet
                // (pre-migration), we still render the page with feature disabled.
                // This prevents template rendering from breaking during database migrations.
                try {
                    $tenantId = $request->getAttribute('tenant_id');
                    if ($tenantId) {
                        // Query the tenant record to check if DeepDive is enabled
                        $stmtDD = $this->pdo->prepare("SELECT deepdive_enabled FROM users WHERE id = :id");
                        $stmtDD->execute(['id' => $tenantId]);
                        $this->twig->addGlobal('tenant', [
                            'id'               => $tenantId,
                            'deepdive_enabled' => (bool)$stmtDD->fetchColumn(),
                        ]);
                    }
                } catch (\Throwable $e) {
                    // Column doesn't exist or other error: default to disabled
                    // Allows pages to load even during database schema migrations
                    $this->twig->addGlobal('tenant', ['deepdive_enabled' => false]);
                }
            } catch (\Exception $e) {
                // User table query failed: possible migration pending or schema change
                // Provide minimal defaults so page still renders
                $this->twig->addGlobal('user_timezone', 'UTC');
                $this->twig->addGlobal('all_timezones', \DateTimeZone::listIdentifiers());
            }
        }

        // === Global Base Path ===
        // Used in Twig templates as {{ base_path }} for URL prefixing
        // Enables app to work correctly when deployed in subdirectories
        $this->twig->addGlobal('base_path', '');

        return $handler->handle($request);
    }
}
