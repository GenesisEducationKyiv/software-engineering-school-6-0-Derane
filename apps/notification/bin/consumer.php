#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RetryPublishFailedException;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPHeartbeatMissedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Psr\Log\LoggerInterface;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';
$buildContainer = require __DIR__ . '/../config/container.php';
$container = $buildContainer($settings);

/** @var LoggerInterface $logger */
$logger = $container->get(LoggerInterface::class);

$consumer = $container->get(SendReleaseEmailConsumer::class);
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

// Keep heartbeats flowing via SIGALRM even while a delivery is processing (a
// slow SMTP send) or the loop is parked in wait(), so the broker never drops
// this long-lived connection for missed heartbeats and a genuinely dead
// connection surfaces within the heartbeat window instead of hanging.
$heartbeat = null;
$connection = $channel->getConnection();
if ($connection !== null && extension_loaded('pcntl')) {
    $heartbeat = new PCNTLHeartbeatSender($connection);
    $heartbeat->register();
}

$consumer->start();
$logger->info('Notification consumer started', ['queue' => SendReleaseEmailConsumer::QUEUE]);

try {
    while ($running && $channel->is_consuming()) {
        try {
            $channel->wait(timeout: 1);
        } catch (AMQPTimeoutException) {
            // No delivery within the poll window — loop to honor shutdown signals.
        }
    }
} catch (AMQPConnectionClosedException | AMQPIOException | AMQPHeartbeatMissedException $e) {
    // Broker connection dropped (restart / missed heartbeat). Exit non-zero and
    // let the supervisor (docker `restart: unless-stopped`) restart us with a
    // fresh connection; in-flight work is idempotent, so a hard restart is safe.
    $heartbeat?->unregister();
    $logger->error('Consumer connection lost — exiting for supervised restart', ['error' => $e->getMessage()]);
    exit(1);
} catch (RetryPublishFailedException $e) {
    // A retry/park republish could not be confirmed by the broker. The original
    // delivery was deliberately left unacked, so it will be redelivered after a
    // fresh connection; fail closed via the same supervised-restart path rather
    // than silently stranding it.
    $heartbeat?->unregister();
    $logger->error('Retry publish unconfirmed — exiting for supervised restart', ['error' => $e->getMessage()]);
    exit(1);
}

$heartbeat?->unregister();

if ($running) {
    // The loop ended without a shutdown signal: the channel stopped consuming
    // because the connection went away. Same supervised-restart path.
    $logger->error('Consumer stopped unexpectedly — exiting for supervised restart');
    exit(1);
}

try {
    $channel->close();
    $connection?->close();
} catch (\Throwable) {
    // Connection already gone on a clean shutdown — nothing left to flush.
}
$logger->info('Notification consumer stopped');
