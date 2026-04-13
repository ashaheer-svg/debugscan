<?php
/**
 * Forensic Database Migration: Users Timezone
 * Run this via CLI: php scripts/migrate_timezone.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

try {
    // Force Load Environment locally if needed
    if (file_exists(__DIR__ . '/../.env')) {
        $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
        $dotenv->load();
    }

    $pdo = App\Database::getConnection();

    echo "Checking 'users' table for 'timezone' column...\n";
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS timezone TEXT;");
    echo "SUCCESS: 'timezone' column ensured in 'users' table.\n";

} catch (\Exception $e) {
    echo "FAILURE: " . $e->getMessage() . "\n";
    exit(1);
}
