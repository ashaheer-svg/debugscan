<?php

declare(strict_types=1);

namespace App\Helpers;

use SessionHandlerInterface;
use PDO;

class DatabaseSessionHandler implements SessionHandlerInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        $stmt = $this->pdo->prepare("SELECT data FROM sessions WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return $row ? $row['data'] : '';
    }

    public function write(string $id, string $data): bool
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO sessions (id, data, last_activity, user_id, ip_address, user_agent)
            VALUES (:id, :data, NOW(), :user_id, :ip, :ua)
            ON CONFLICT (id) DO UPDATE SET
                data = EXCLUDED.data,
                last_activity = NOW(),
                user_id = EXCLUDED.user_id,
                ip_address = EXCLUDED.ip_address,
                user_agent = EXCLUDED.user_agent
        ");

        $stmt->execute([
            'id' => $id,
            'data' => $data,
            'user_id' => $_SESSION['user_id'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ]);

        return true;
    }

    public function destroy(string $id): bool
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        $stmt = $this->pdo->prepare("DELETE FROM sessions WHERE last_activity < NOW() - INTERVAL '1 second' * :max_lifetime");
        $stmt->execute(['max_lifetime' => $max_lifetime]);

        return (int) $stmt->rowCount();
    }
}
