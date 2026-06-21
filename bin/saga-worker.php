<?php

declare(strict_types=1);

use App\Saga\Enrollment\Infrastructure\Worker\SagaWorker;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

// The supervised loop owns its own exit code: 0 on a clean shutdown-signal exit,
// 1 on connection loss so docker `restart: unless-stopped` restarts us with a fresh
// connection (in-flight work is idempotent — a hard restart is safe).
$exitCode = $container->get(SagaWorker::class)->run();

exit($exitCode);
