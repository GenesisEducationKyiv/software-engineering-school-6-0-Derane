<?php

declare(strict_types=1);

use Notification\Welcome\V1\WelcomeEmailServiceInterface;
use Spiral\RoadRunner\GRPC\Invoker;
use Spiral\RoadRunner\GRPC\Server;
use Spiral\RoadRunner\Worker;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$server = new Server(new Invoker(), [
    'debug' => false,
]);

$server->registerService(
    WelcomeEmailServiceInterface::class,
    $container->get(WelcomeEmailServiceInterface::class)
);

$server->serve(Worker::create());
