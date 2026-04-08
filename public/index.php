<?php

declare(strict_types=1);

use App\AppBootstrap;

require __DIR__ . '/../vendor/autoload.php';

$app = AppBootstrap::create();
$app->run();
