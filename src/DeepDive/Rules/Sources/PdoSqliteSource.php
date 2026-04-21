<?php

declare(strict_types=1);

namespace App\DeepDive\Rules\Sources;

use PDO;

/**
 * Opens a SQLite file read-only via PDO. Reused by rules across a single
 * evaluation run — the PDO handle is lazily created.
 */
final class PdoSqliteSource implements SqliteSource
{
    private ?PDO $pdo = null;

    public function __construct(
        private readonly string $name,
        private readonly string $path,
    ) {}

    public function name(): string { return $this->name; }
    public function path(): string { return $this->path; }

    public function query(string $sql, array $params = []): array
    {
        if ($this->pdo === null) {
            // mode=ro + immutable=1 guards against schema surprises and keeps
            // us from accidentally mutating recovered artifacts.
            $dsn = 'sqlite:' . $this->path;
            $this->pdo = new PDO($dsn, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA query_only = ON');
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        /** @var array<int,array<string,mixed>> $rows */
        $rows = $stmt->fetchAll();
        return $rows;
    }
}
