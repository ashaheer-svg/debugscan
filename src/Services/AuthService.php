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
        $stmt = $this->pdo->prepare("SELECT id, email, password_hash, display_name, role, status, tenant_id, timezone, failed_login_count, locked_until FROM users WHERE email = :email");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();

        if (!$user) {
            return null;
        }

        // 1. Check account status
        if ($user['status'] !== 'active') {
            throw new RuntimeException("Account is " . $user['status']);
        }

        // 2. Check for temporary lockout
        if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
            $remaining = ceil((strtotime($user['locked_until']) - time()) / 60);
            throw new RuntimeException("Account is temporarily locked. Please try again in $remaining minutes.");
        }

        // 3. Verify password
        if (password_verify($password, $user['password_hash'])) {
            // Successful login
            $this->updateLoginMetrics($user['id']);
            return $user;
        }

        // 4. Record failure
        $this->recordFailedAttempt($user['id'], (int)$user['failed_login_count']);

        return null;
    }

    private function recordFailedAttempt(string $userId, int $currentCount): void
    {
        $newCount = $currentCount + 1;
        $lockUntil = null;

        if ($newCount >= 5) {
            $lockUntil = date('Y-m-d H:i:s', strtotime('+15 minutes'));
        }

        $stmt = $this->pdo->prepare("UPDATE users SET failed_login_count = :count, locked_until = :lock, updated_at = NOW() WHERE id = :id");
        $stmt->execute(['count' => $newCount, 'lock' => $lockUntil, 'id' => $userId]);
    }

    private function updateLoginMetrics(string $userId): void
    {
        $stmt = $this->pdo->prepare("UPDATE users SET last_login_at = NOW(), failed_login_count = 0, locked_until = NULL WHERE id = :id");
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
