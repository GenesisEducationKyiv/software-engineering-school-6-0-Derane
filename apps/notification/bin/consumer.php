#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$consumer = $container->get(SendReleaseEmailConsumer::class);
$consumer->start();

$channel = $container->get(RabbitConnection::class)->channel();

while ($channel->is_consuming()) {
    $channel->wait();
}
