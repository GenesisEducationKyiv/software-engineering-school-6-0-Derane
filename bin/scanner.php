<?php

declare(strict_types=1);

use App\Migration\Migrator;
use App\Service\ScannerService;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$container->get(Migrator::class)->migrate();

$scanner = $container->get(ScannerService::class);
$logger = $container->get(LoggerInterface::class);
$interval = $settings['github']['scan_interval'];

$logger->info("Scanner started. Checking every {$interval} seconds.");

while (true) {
    $scanner->scan();
    sleep($interval);
}
