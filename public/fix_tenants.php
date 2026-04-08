<?php
require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use Dotenv\Dotenv;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("UPDATE users SET tenant_id = id WHERE role = 'tenant' AND tenant_id IS NULL");
    $stmt->execute();
    echo "Fixed " . $stmt->rowCount() . " tenants.";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage();
}
