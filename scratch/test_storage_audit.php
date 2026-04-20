<?php
// Mock test for getStorageBreakdown
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

$dsn = "pgsql:host={$dbConfig['host']};port={$dbConfig['port']};dbname={$dbConfig['db']}";
$pdo = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Mock services
$view = $this->createMock(\Twig\Environment::class); // Not needed for this endpoint
$ai = $this->createMock(\App\Services\AiService::class);
$mail = $this->createMock(\App\Services\MailService::class);
$plans = $this->createMock(\App\Services\ReportPlanService::class);

$controller = new AdminController($view, $pdo, $ai, '/admin', $mail, $plans);

// Get a random tenant ID
$tenantId = $pdo->query("SELECT id FROM users WHERE role = 'tenant' LIMIT 1")->fetchColumn();

if (!$tenantId) {
    echo "No tenant found in DB to test.\n";
    exit;
}

$request = (new ServerRequestFactory())->createServerRequest('GET', "/admin/tenants/storage/$tenantId");
$response = new Response();

try {
    $result = $controller->getStorageBreakdown($request, $response, ['id' => $tenantId]);
    echo "API Result:\n";
    echo $result->getBody();
    echo "\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
