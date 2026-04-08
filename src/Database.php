<?php

declare(strict_types=1);

namespace App;

use PDO;
use RuntimeException;

class Database
{
    public static function getConnection(): PDO
    {
        $host = $_ENV['DB_HOST'] ?? 'localhost';
        $port = $_ENV['DB_PORT'] ?? '5432';
        $db   = $_ENV['DB_NAME'] ?? 'debugscan';
        $user = $_ENV['DB_USER'] ?? 'postgres';
        $pass = $_ENV['DB_PASS'] ?? '';

        $dsn = "pgsql:host=$host;port=$port;dbname=$db;sslmode=disable";
        
        try {
            $pdo = new PDO($dsn, $user, $pass, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            throw new RuntimeException("Database connection failed: " . $e->getMessage());
        }
    }

    public static function setTenantContext(PDO $pdo, ?string $tenantId, string $role): void
    {
        if ($role === 'admin') {
            $pdo->exec("SET app.current_user_role = 'admin'");
            $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
        } else {
            $pdo->exec("SET app.current_user_role = 'tenant'");
            if ($tenantId) {
                $quotedTenantId = $pdo->quote($tenantId);
                $pdo->exec("SET app.current_tenant_id = $quotedTenantId");
            } else {
                $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");
            }
        }
    }
}
