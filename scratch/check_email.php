<?php
// require_once __DIR__ . '/../public/index.php'; 

require_once __DIR__ . '/../src/Database.php';
use App\Database;

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->query("SELECT smtp_host, reporting_email FROM system_settings LIMIT 1");
    $settings = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "--- System Settings ---\n";
    print_r($settings);
    
    $stmt = $pdo->query("SELECT action, details, created_at FROM audit_log WHERE action IN ('email_failed', 'tokens_requested') ORDER BY created_at DESC LIMIT 10");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\n--- Recent Email Audit Logs ---\n";
    if (empty($logs)) {
        echo "No recent email events found in audit_log.\n";
    } else {
        foreach ($logs as $log) {
            echo "[{$log['created_at']}] ACTION: {$log['action']}\n";
            echo "DETAILS: {$log['details']}\n";
            echo str_repeat('-', 40) . "\n";
        }
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
