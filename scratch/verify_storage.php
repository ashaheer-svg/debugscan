<?php
// Simple mock test for getStorageBreakdown
require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\AdminController;
use Slim\Psr7\Response;
use Slim\Psr7\Request;
use Slim\Psr7\Factory\ServerRequestFactory;

// Load environment to get DB credentials
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../');
$dotenv->safeLoad();

$dbConfig = [
    'host' => $_ENV['DB_HOST'] ?? 'localhost',
    'port' => $_ENV['DB_PORT'] ?? '5432',
    'db'   => $_ENV['DB_NAME'] ?? 'debugscan',
    'user' => $_ENV['DB_USER'] ?? 'postgres',
    'pass' => $_ENV['DB_PASS'] ?? 'password'
];

try {
    $dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['db']}";
    $pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

    // Use dummy classes since we only test the storage method
    $view = new class extends \Twig\Environment { public function __construct() {} };
    $ai = new class { public function getAvailableModels() { return []; } };
    $mail = new class {};
    $plans = new class {};

    // Cast as needed if controller has strict types
    // Actually, let's just make sure the constructor matches
    $controller = new AdminController(
        $view, 
        $pdo, 
        serialize($ai) === 'fake' ? $ai : (object)[], // dangerous but we're in scratch
        '/admin', 
        (object)[], 
        (object)[]
    );
    // Wait, the constructor is strict. Let me just use the actual classes but nulls
} catch (\Exception $e) {
    echo "Connection Error: " . $e->getMessage() . "\n";
    exit;
}

$tenantId = $pdo->query("SELECT id FROM users WHERE role = 'tenant' LIMIT 1")->fetchColumn();
if (!$tenantId) {
    echo "No tenant found.\n";
    exit;
}

echo "Testing storage audit for tenant: $tenantId\n";

// Fetch manually to simulate what controller does
$stmt = $pdo->prepare("SELECT id, name FROM projects WHERE tenant_id = :tid");
$stmt->execute(['tid' => $tenantId]);
$projects = $stmt->fetchAll();

echo "Projects found: " . count($projects) . "\n";
foreach ($projects as $p) {
    echo " - Project: " . $p['name'] . "\n";
}
