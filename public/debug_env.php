<?php
// Temporary diagnostic file - DELETE after debugging
require __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
$dotenv->load();

echo "DB_HOST: " . getenv('DB_HOST') . "\n";
echo "DB_NAME: " . getenv('DB_NAME') . "\n";
echo "DB_USER: " . getenv('DB_USER') . "\n";
echo "DB_PASS is set: " . (getenv('DB_PASS') ? 'YES' : 'NO') . "\n";

try {
    $pdo = new PDO(
        "pgsql:host=" . getenv('DB_HOST') . ";port=" . getenv('DB_PORT') . ";dbname=" . getenv('DB_NAME'),
        getenv('DB_USER'),
        getenv('DB_PASS')
    );
    echo "DB CONNECTION: SUCCESS\n";
} catch (Exception $e) {
    echo "DB CONNECTION FAILED: " . $e->getMessage() . "\n";
}
