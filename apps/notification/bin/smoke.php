#!/usr/bin/env php
<?php

declare(strict_types=1);

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

require __DIR__ . '/../vendor/autoload.php';

$settings = require __DIR__ . '/../config/settings.php';

$token = 'smoke-' . bin2hex(random_bytes(4));
$recipient = $token . '@example.test';
$payload = [
    'schema' => 'SendReleaseEmail/v1',
    'eventId' => sprintf(
        '%08s-%04s-4%03s-8%03s-%012s',
        bin2hex(random_bytes(4)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(2)),
        bin2hex(random_bytes(6)),
    ),
    'occurredAt' => (new DateTimeImmutable())->format(DateTimeInterface::RFC3339),
    'subscriptionId' => 9001,
    'email' => $recipient,
    'repository' => 'smoke/repo-' . $token,
    'release' => [
        'tagName' => 'v0-' . $token,
        'name' => 'Smoke Release ' . $token,
        'htmlUrl' => 'https://example.test/releases/' . $token,
        'publishedAt' => (new DateTimeImmutable())->format(DateTimeInterface::RFC3339),
    ],
];

$connection = new AMQPStreamConnection(
    $settings['rabbitmq']['host'],
    $settings['rabbitmq']['port'],
    $settings['rabbitmq']['user'],
    $settings['rabbitmq']['password'],
    $settings['rabbitmq']['vhost'],
);

$channel = $connection->channel();
$message = new AMQPMessage(
    json_encode($payload, JSON_THROW_ON_ERROR),
    [
        'content_type' => 'application/json',
        'delivery_mode' => 2,
    ]
);

$channel->basic_publish($message, 'notifications', 'release.email');
$channel->close();
$connection->close();

$mailhogUrl = 'http://mailhog:8025/api/v2/messages';
$deadline = time() + 20;

do {
    $body = @file_get_contents($mailhogUrl);
    if (is_string($body) && str_contains($body, $token)) {
        fwrite(STDOUT, sprintf("Smoke delivery observed in MailHog for token %s\n", $token));
        exit(0);
    }

    usleep(500000);
} while (time() < $deadline);

fwrite(STDERR, sprintf("Smoke delivery not observed in MailHog for token %s\n", $token));
exit(1);
