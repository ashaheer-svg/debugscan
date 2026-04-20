<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Database;
use Dotenv\Dotenv;

// Load environment variables for DB connectivity
if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

/**
 * Forensic Report Plans Seeder
 * This script wipes existing report plans and implements the 5 new standard forensic profiles.
 */

try {
    $pdo = Database::getConnection();
    
    // Disable RLS temporarily or set admin context to allow deletions
    $pdo->exec("SET app.current_user_role = 'admin'");
    $pdo->exec("SET app.current_tenant_id = '00000000-0000-0000-0000-000000000000'");

    $pdo->beginTransaction();

    echo "Deactivating existing report plans to preserve forensic history integrity...\n";
    $pdo->exec("UPDATE report_plans SET is_active = false");

    $plans = [
        [
            'name' => 'Filesystem & Volume Integrity',
            'header' => 'You must focus on volume mount status, Btrfs scrub errors, and filesystem capacity saturation. Analyze the relationship between disk IOwait and filesystem responsiveness.',
            'parsers' => [
                'version' => ['enabled' => true, 'rows' => null],
                'hardware' => ['enabled' => true, 'rows' => null],
                'volumes' => ['enabled' => true, 'rows' => null],
                'btrfs' => ['enabled' => true, 'rows' => null],
                'storage_util' => ['enabled' => true, 'rows' => null],
                'logs' => ['enabled' => true, 'rows' => 500],
                'db_system_events' => ['enabled' => true, 'rows' => 100],
            ]
        ],
        [
            'name' => 'RAID & Drive Health',
            'header' => 'Focus explicitly on RAID array consistency, S.M.A.R.T attributes (UNC, Reallocations), and disk I/O latency metrics. Provide a definitive risk assessment for data loss.',
            'parsers' => [
                'version' => ['enabled' => true, 'rows' => null],
                'hardware' => ['enabled' => true, 'rows' => null],
                'disks' => ['enabled' => true, 'rows' => null],
                'raid' => ['enabled' => true, 'rows' => null],
                'disk_io' => ['enabled' => true, 'rows' => null],
                'db_disk_health' => ['enabled' => true, 'rows' => null],
                'db_disk_events' => ['enabled' => true, 'rows' => 200],
            ]
        ],
        [
            'name' => 'Network Infrastructure Audit',
            'header' => 'Focus on physical layer issues: link speed negotiation, CRC error rates, carrier loss events, and TCP connection exhaustion states.',
            'parsers' => [
                'version' => ['enabled' => true, 'rows' => null],
                'hardware' => ['enabled' => true, 'rows' => null],
                'network' => ['enabled' => true, 'rows' => null],
                'network_hardware' => ['enabled' => true, 'rows' => null],
                'db_connection_logs' => ['enabled' => true, 'rows' => 100],
                'auth_timeline' => ['enabled' => true, 'rows' => 200],
            ]
        ],
        [
            'name' => 'Performance & I/O Bottlenecks',
            'header' => 'Identify kernel-level blocking. Focus on CPU iowait%, memory pressure (pgscan), zombie processes, and processes stuck in uninterruptible sleep (D-state).',
            'parsers' => [
                'version' => ['enabled' => true, 'rows' => null],
                'hardware' => ['enabled' => true, 'rows' => null],
                'system_load' => ['enabled' => true, 'rows' => null],
                'memory_util' => ['enabled' => true, 'rows' => null],
                'dstate' => ['enabled' => true, 'rows' => 50],
                'disk_io' => ['enabled' => true, 'rows' => null],
                'storage_util' => ['enabled' => true, 'rows' => null],
            ]
        ],
        [
            'name' => 'Global Hardware Identity',
            'header' => 'Perform a high-fidelity audit of the physical hardware inventory, serial numbers, thermal status, and DSM configuration compliance.',
            'parsers' => [
                'version' => ['enabled' => true, 'rows' => null],
                'hardware' => ['enabled' => true, 'rows' => null],
                'disks' => ['enabled' => true, 'rows' => null],
                'network_hardware' => ['enabled' => true, 'rows' => null],
                'storage_util' => ['enabled' => true, 'rows' => null],
            ]
        ]
    ];

    foreach ($plans as $p) {
        echo "Implementing Plan: {$p['name']}...\n";
        
        $stmt = $pdo->prepare("
            INSERT INTO report_plans (name, prompt_header, ai_model, token_charge, is_active)
            VALUES (:name, :header, 'llama-3.3-70b-versatile', 150000, true)
            RETURNING id
        ");
        
        $stmt->execute([
            'name' => $p['name'],
            'header' => $p['header']
        ]);
        
        $planId = $stmt->fetchColumn();

        // Assign to all tenants
        $pdo->prepare("
            INSERT INTO tenant_report_plans (tenant_id, report_plan_id)
            SELECT id, :pid FROM users WHERE role = 'tenant'
        ")->execute(['pid' => $planId]);

        // Insert extraction configs for this plan
        foreach ($p['parsers'] as $key => $conf) {
            $pdo->prepare("
                INSERT INTO extraction_config (report_plan_id, section_key, is_enabled, max_rows)
                VALUES (:pid, :key, :enabled, :rows)
            ")->execute([
                'pid' => $planId,
                'key' => $key,
                'enabled' => $conf['enabled'] ? 'true' : 'false',
                'rows' => $conf['rows']
            ]);
        }
        
        // Ensure other parsers are present but disabled
        $allSections = [
            'version', 'hardware', 'disks', 'raid', 'volumes', 'btrfs', 'storage_util',
            'disk_io', 'logs', 'dstate', 'system_load', 'memory_util', 'network',
            'network_hardware', 'db_system_events', 'db_disk_health', 'db_connection_logs', 'db_disk_events',
            'audit_db', 'auth_timeline', 'smb_xfer'
        ];
        
        foreach ($allSections as $s) {
            if (!isset($p['parsers'][$s])) {
                $pdo->prepare("
                    INSERT INTO extraction_config (report_plan_id, section_key, is_enabled, max_rows)
                    VALUES (:pid, :key, false, NULL)
                    ON CONFLICT DO NOTHING
                ")->execute(['pid' => $planId, 'key' => $s]);
            }
        }
    }

    $pdo->commit();
    echo "SUCCESS: 5 forensic report plans implemented and assigned to all tenants.\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
