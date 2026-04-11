<?php
require __DIR__ . '/../src/Database.php';

use App\Database;

$pdo = Database::getConnection();

try {
    echo "Adding 'max_prompt_chars' column to 'system_settings' table...\n";
    $pdo->exec("ALTER TABLE system_settings ADD COLUMN IF NOT EXISTS max_prompt_chars INTEGER DEFAULT 50000");
    echo "Database upgrade successfully completed.\n";
} catch (Exception $e) {
    echo "Upgrade failed: " . $e->getMessage() . "\n";
}
