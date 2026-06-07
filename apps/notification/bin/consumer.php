#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;

require __DIR__ . '/../vendor/autoload.php';

// No phpdotenv — this service reads configuration directly from $_ENV/getenv()
// (Decision §5: matches how the container actually receives its config in its
// real deployment — Docker injects env vars directly; .env-file loading would
// only matter for a local-dev workflow this service doesn't support yet, D6's
// job). settings → container($settings) → resolve → consume, exactly mirroring
// the monolith's bin/scanner.php bootstrap shape.
$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

$consumer = $container->get(SendReleaseEmailConsumer::class);
$consumer->start();

// Blocking consume loop — the standard php-amqplib idiom that keeps this
// long-lived worker process alive: basic_consume() (registered by
// SendReleaseEmailConsumer::start()) only arms the callback; wait() is what
// actually blocks on the socket and dispatches deliveries to it. is_consuming()
// flips to false if the channel/connection is torn down (e.g. broker closes
// the connection), which lets the loop — and the process — exit cleanly
// instead of spinning on a dead channel.
$channel = $container->get(RabbitConnection::class)->channel();

while ($channel->is_consuming()) {
    $channel->wait();
}
