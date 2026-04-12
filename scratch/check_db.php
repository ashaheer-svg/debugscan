<?php
require __DIR__ . '/../vendor/autoload.php';
$pdo = App\Database::getConnection();
$stmt = $pdo->query("
    SELECT 
        column_name, 
        data_type 
    FROM information_schema.columns 
    WHERE table_name = 'scan_jobs' 
    AND column_name = 'result_input_payload'
");
print_r($stmt->fetch(PDO::FETCH_ASSOC));
