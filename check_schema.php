<?php
$pdo = new PDO('pgsql:host=localhost;dbname=debugscan', 'postgres', 'password');
$stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'system_settings'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
