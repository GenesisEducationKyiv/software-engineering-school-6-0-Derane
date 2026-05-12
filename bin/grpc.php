<?php

declare(strict_types=1);

use App\Grpc\ReleaseNotifierService;
use Grpc\ReleaseNotifier\V1\ReleaseNotifierServiceInterface;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

require __DIR__ . '/../vendor/autoload.php';

if (file_exists(__DIR__ . '/../.env')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$server = new Server(new Invoker(), [
    'debug' => false,
]);

$server->registerService(
    ReleaseNotifierServiceInterface::class,
    $container->get(ReleaseNotifierService::class)
);

$server->serve(Worker::create());
