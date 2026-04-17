<?php
// scratch/apply_migration_013.php
require __DIR__ . '/../vendor/autoload.php';
use App\Database;
use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

putenv('DB_HOST=127.0.0.1'); // Bypass localhost IPv6 issues on some Windows setups
$pdo = Database::getConnection();
$sql = file_get_contents(__DIR__ . '/../migrations/013_token_workflow.sql');

try {
    $pdo->exec($sql);
    echo "Migration 013 applied successfully.\n";
} catch (\Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
