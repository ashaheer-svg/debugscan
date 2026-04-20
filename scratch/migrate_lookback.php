<?php
require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(dirname(__DIR__));
$dotenv->load();

$host = $_ENV['DB_HOST'];
$db   = $_ENV['DB_NAME'];
$user = $_ENV['DB_USER'];
$pass = $_ENV['DB_PASS'];
$port = $_ENV['DB_PORT'];

$dsn = "pgsql:host=$host;port=$port;dbname=$db;user=$user;password=$pass";

try {
    $pdo = new PDO($dsn);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Updating report_plans table...\n";
    $pdo->exec("ALTER TABLE report_plans ADD COLUMN IF NOT EXISTS master_lookback_days INTEGER DEFAULT 0;");
    echo "Done.\n";

} catch (\PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
