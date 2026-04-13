<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Models\User;
use RuntimeException;

class AuthService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function authenticate(string $email, string $password): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, email, password_hash, display_name, role, status, tenant_id, timezone FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        if ($user['status'] !== 'active') {
            throw new RuntimeException("Account is " . $user['status']);
        }

        if (password_verify($password, $user['password_hash'])) {
            // Successful login
            $this->updateLoginMetrics($user['id']);
            return $user;
        }

        return null;
    }

    private function updateLoginMetrics(string $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW(), failed_login_count = 0 WHERE id = :id");
        $stmt->execute(['id' => $userId]);
    }

    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public function updateProfile(string $userId, string $displayName, ?string $timezone = null, ?string $password = null): void
    {
        if ($password) {
            $stmt = $this->pdo->prepare("UPDATE users SET display_name = :name, password_hash = :pass, timezone = :tz, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                'name' => $displayName,
                'pass' => $this->hashPassword($password),
                'tz' => $timezone,
                'id' => $userId
            ]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE users SET display_name = :name, timezone = :tz, updated_at = NOW() WHERE id = :id");
            $stmt->execute([
                'name' => $displayName,
                'tz' => $timezone,
                'id' => $userId
            ]);
        }
    }
}
