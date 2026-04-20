<?php
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$host = $_ENV['DB_HOST'] ?: 'localhost';
$port = $_ENV['DB_PORT'] ?: '5432';
$db   = $_ENV['DB_NAME'] ?: 'debugscan';
$user = $_ENV['DB_USER'] ?? 'postgres';
$pass = $_ENV['DB_PASS'] ?? '';

$dsn = "pgsql:host=$host;port=$port;dbname=$db";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

$stmt = $pdo->query("SELECT id, email, role, tenant_id FROM users ORDER BY created_at DESC LIMIT 5");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Latest Users:\n";
foreach ($users as $u) {
    printf("ID: %s | Email: %s | Role: %s | TenantID: %s\n", 
        $u['id'], 
        $u['email'], 
        $u['role'], 
        $u['tenant_id'] ?: 'NULL'
    );
}
