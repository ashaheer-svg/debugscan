<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

/**
 * Database Factory & Tenant Context Manager
 *
 * RESPONSIBILITIES:
 * - Factory method for creating/retrieving the application's PDO connection instance
 * - Establishes PostgreSQL connection with error handling and configured attributes
 * - Manages multi-tenant database isolation via RLS (Row-Level Security) context variables
 * - Sets PostgreSQL session variables for row-level security enforcement
 *
 * ARCHITECTURE NOTES:
 * PostgreSQL RLS is implemented using SET commands to store current user context in session
 * variables. These are referenced in table policies to filter rows by tenant ownership.
 * This approach centralizes tenant isolation at the database layer rather than application logic.
 *
 * INTEGRATION:
 * - Used by AppBootstrap to register PDO instance in DI container
 * - Called by AuthMiddleware after session validation to set RLS context
 * - Dependency injected into all services/controllers that need database access
 *
 * CONFIGURATION (from .env):
 * - DB_HOST: PostgreSQL server hostname (default: localhost)
 * - DB_PORT: PostgreSQL server port (default: 5432)
 * - DB_NAME: Database name (default: aidebugscan)
 * - DB_USER: Database user (default: postgres)
 * - DB_PASS: Database password (default: empty string)
 *
 * @package App
 * @final Cannot be subclassed due to static factory pattern
 */
class Database
{
    /**
     * Factory method to establish and configure PostgreSQL database connection
     *
     * BEHAVIOR:
     * 1. Reads connection parameters from environment variables with sensible defaults
     * 2. Constructs PostgreSQL DSN with SSL disabled (development mode)
     * 3. Creates PDO instance with exception-driven error handling
     * 4. Disables prepared statement emulation for true parameterized queries
     * 5. Sets default fetch mode to associative arrays for consistency
     *
     * ERROR HANDLING:
     * PDOException caught and wrapped in RuntimeException for clearer semantics.
     * Original exception message preserved for debugging.
     *
     * @return PDO Configured database connection instance with exception mode enabled
     *
     * @throws RuntimeException When connection fails (host unreachable, auth failure, etc.)
     *                          Original PDOException message included in error text
     */
    public static function getConnection(): PDO
    {
        // Read connection parameters from environment with sensible defaults
        $host = getenv('DB_HOST') ?: 'localhost';
        $port = getenv('DB_PORT') ?: '5432';
        $db   = getenv('DB_NAME') ?: 'aidebugscan';
        $user = getenv('DB_USER') ?: 'postgres';
        $pass = getenv('DB_PASS') ?: '';

        // Build PostgreSQL DSN; sslmode=disable for development environments
        $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=disable";

        try {
            // Create PDO with exception-driven error handling and prepared statement safety
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Throw exceptions instead of silent failures
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,        // Return rows as associative arrays
                PDO::ATTR_EMULATE_PREPARES   => false,                    // Use native prepared statements, not emulation
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    /**
     * Configure PostgreSQL session variables for row-level security enforcement
     *
     * PURPOSE:
     * Sets session variables that PostgreSQL RLS policies reference to determine
     * which rows a user can access. All subsequent queries in this connection
     * will respect the tenant context until it changes.
     *
     * CONTEXT LOGIC:
     * - Admin users: Set to null tenant (null-like UUID) to bypass tenant filters entirely
     * - Tenant users: Set to their assigned tenant_id to restrict access to owned rows
     * - Guest/unauthenticated: Use null-like UUID as fallback (typically blocks access)
     *
     * DATABASE RLS ARCHITECTURE:
     * PostgreSQL policies on tables check these variables:
     *   - app.current_user_role: Either 'admin' or 'tenant'
     *   - app.current_tenant_id: UUID of allowed tenant, or all-zeros for null context
     *
     * EXAMPLE POLICY:
     *   CREATE POLICY tenant_isolation ON projects
     *     USING (tenant_id = current_setting('app.current_tenant_id')::uuid)
     *
     * @param PDO    $pdo       Active database connection
     * @param ?string $tenantId  Tenant UUID (null for guests/admins)
     * @param string  $role      User role: 'admin' or 'tenant'
     *
     * @return void
     *
     * @note Uses pdo->exec() (not prepared statements) since these are session config
     * @note Tenant ID is quoted via PDO::quote() to safely embed as string literal
     */
    public static function setTenantContext(PDO $pdo, ?string $tenantId, string $role): void
    {
        if ($role === 'admin') {
            // Admin users: set role to admin and clear tenant ID (full database access)
            $pdo->exec("SET app.current_user_role = 'admin'");
            $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
        } else {
            // Tenant users: set role and apply tenant filter
            $pdo->exec("SET app.current_user_role = 'tenant'");
            if ($tenantId) {
                // Use PDO::quote() to safely embed the UUID as a string literal in SQL
                $quotedTenantId = $pdo->quote($tenantId);
                $pdo->exec("SET app.current_tenant_id = $quotedTenantId");
            } else {
                // No tenant ID: use all-zeros UUID (acts as null context, blocks access)
                $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
            }
        }
    }
}
