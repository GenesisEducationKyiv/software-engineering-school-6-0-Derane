#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

/** @var LoggerInterface $logger */
$logger = $container->get(LoggerInterface::class);

$consumer = $container->get(SendReleaseEmailConsumer::class);
$consumer->start();

$channel = $container->get(RabbitConnection::class)->channel();

// Graceful shutdown: SIGTERM/SIGINT flip the flag; the wait() loop below
// re-checks it at least once per second, so an in-flight delivery always
// finishes (ack/nack) before the process exits. Idempotency makes a hard
// kill safe regardless — this just makes shutdown a non-event.
$running = true;
if (extension_loaded('pcntl')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $signal) {
        pcntl_signal($signal, static function () use (&$running, $logger): void {
            $logger->info('Shutdown signal received — finishing in-flight delivery and exiting');
            $running = false;
        });
    }
}

$logger->info('Notification consumer started', ['queue' => SendReleaseEmailConsumer::QUEUE]);

while ($running && $channel->is_consuming()) {
    try {
        $channel->wait(timeout: 1);
    } catch (AMQPTimeoutException) {
        // No delivery within the poll window — loop to honor shutdown signals.
    }
}

$channel->close();
$logger->info('Notification consumer stopped');
