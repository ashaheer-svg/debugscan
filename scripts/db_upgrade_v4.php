<?php
require __DIR__ . '/../src/Database.php';

use App\Database;

$pdo = Database::getConnection();

try {
    echo "Adding 'is_truncated' column to 'scan_jobs' table...\n";
    $pdo->exec("ALTER TABLE scan_jobs ADD COLUMN IF NOT EXISTS is_truncated BOOLEAN DEFAULT FALSE");
    echo "Database upgrade successfully completed.\n";
} catch (Exception $e) {
    echo "Upgrade failed: " . $e->getMessage() . "\n";
}
