<?php

declare(strict_types=1);

use App\AppBootstrap;

require __DIR__ . '/../src/App.php';

$app = AppBootstrap::create();
$app->run();
