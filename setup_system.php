<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';

use App\Database;
use Dotenv\Dotenv;

// Load Environment Variables
$dotenv = Dotenv::createUnsafeImmutable(__DIR__);
$dotenv->load();

$pdo = Database::getConnection();

echo "AI DebugScan v3 - Core Provisioning Script\n";
echo "========================================\n\n";

echo "DEBUG: DB Name from ENV: " . ($_ENV['DB_NAME'] ?? 'NOT SET') . "\n";
echo "DEBUG: Actual DB Connected: " . $pdo->query("SELECT current_database()")->fetchColumn() . "\n";

try {
    // 1. Create System Admin
    $adminId = '00000000-0000-4000-a000-000000000001';
    $password = password_hash('admin123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("
        INSERT INTO users (id, email, password_hash, role, display_name, status)
        VALUES (:id, :email, :pass, 'admin', 'System Administrator', 'active')
        ON CONFLICT (email) DO NOTHING
    ");
    $stmt->execute(['id' => $adminId, 'email' => 'admin@debugscan.ia', 'pass' => $password]);
    echo "✔ Admin account created (admin@debugscan.ia / admin123)\n";

    // 2. Create Demo Tenant
    $tenantId = '00000000-0000-4000-a000-000000000002';
    $tPassword = password_hash('tenant123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("
        INSERT INTO users (id, email, password_hash, role, display_name, status, tokens_available)
        VALUES (:id, :email, :pass, 'tenant', 'Demo Organization', 'active', 500000)
        ON CONFLICT (email) DO NOTHING
    ");
    $stmt->execute(['id' => $tenantId, 'email' => 'demo@client.ia', 'pass' => $tPassword]);
    echo "✔ Tenant account created (demo@client.ia / tenant123)\n";

    // 3. Initialize System Settings
    $stmt = $pdo->prepare("
        INSERT INTO system_settings (id, groq_api_key_encrypted, level1_model, level2_model, retention_days)
        VALUES (1, 'initial_setup_placeholder', 'llama-3.3-70b-versatile', 'llama-3.1-8b-instant', 30)
        ON CONFLICT (id) DO NOTHING
    ");
    $stmt->execute();
    echo "✔ Default AI settings initialized.\n";

    echo "\nProvisioning Complete! Please ensure 'composer install' has been run.\n";

} catch (\Exception $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
