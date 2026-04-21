<?php

declare(strict_types=1);

/**
 * DeepDive worker entry point.
 *
 *   php workers/deepdive_worker.php         # daemon (polls forever, rotates)
 *   php workers/deepdive_worker.php once    # claim + run a single job, exit
 *
 * Separate process from workers/scan_worker.php. Different PID, different
 * queue table (deepdive_jobs). A crash here does not affect scans.
 *
 * Run under a supervisor (systemd/Supervisord/PM2) — the worker
 * deliberately exits after N jobs or a memory threshold so the supervisor
 * gets a clean restart and leaks stay harmless.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\DeepDive\Services\JobRepository;
use App\DeepDive\Support\Paths;
use App\DeepDive\Worker\Daemon;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createUnsafeImmutable(__DIR__ . '/../');
    $dotenv->load();
}

Paths::ensure(Paths::storageRoot() . '/logs');

$logger = new Logger('deepdive');
$logger->pushHandler(new StreamHandler(Paths::storageRoot() . '/logs/worker.log', Logger::INFO));
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

try {
    $pdo = Database::getConnection();
    Database::setTenantContext($pdo, null, 'admin'); // bypass RLS for worker

    $jobs   = new JobRepository($pdo);
    $daemon = new Daemon($pdo, $jobs, $logger);

    $once = isset($argv[1]) && $argv[1] === 'once';
    $daemon->run($once);
} catch (\Throwable $e) {
    $logger->error('[DeepDive] Worker fatal: ' . $e->getMessage(), [
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ]);
    exit(1);
}
