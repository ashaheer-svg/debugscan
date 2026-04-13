<?php
/**
 * Forensic Database Migration: Users Timezone
 * Run this via CLI: php scripts/migrate_timezone.php
 */
require_once __DIR__ . '/../vendor/autoload.php';

// Direct PDO connection using environment variables
$host = getenv('DB_HOST') ?: '142.91.101.142';
$db   = getenv('DB_NAME') ?: 'debugscan';
$user = getenv('DB_USER') ?: 'ashaheer';
$pass = getenv('DB_PASS') ?: 'fV-Q&#uQ6V2!4m!!Xp7';
$port = getenv('DB_PORT') ?: '5432';

try {
    $dsn = "pgsql:host=$host;port=$port;dbname=$db";
    $pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    echo "Checking 'users' table for 'timezone' column...\n";
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS timezone TEXT;");
    echo "SUCCESS: 'timezone' column ensured in 'users' table.\n";

} catch (\Exception $e) {
    echo "FAILURE: " . $e->getMessage() . "\n";
    exit(1);
}
