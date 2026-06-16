<?php

declare(strict_types=1);

namespace Tests\Notification;

use PHPUnit\Framework\TestCase;

/**
 * Build-hygiene lock for the extracted notification service image: it must talk
 * to RabbitMQ through the pure-PHP php-amqplib client only, never the native
 * C `amqp` PECL extension (rabbitmq-c). Relocated here when the resilience proof
 * was removed; the assertion itself is unrelated to that proof.
 */
final class NotificationServiceImageTest extends TestCase
{
    public function testNotificationImageUsesPurePhpAmqpClientOnly(): void
    {
        $dockerfile = (string) file_get_contents(__DIR__ . '/../../apps/notification/Dockerfile');

        self::assertStringNotContainsString('rabbitmq-c-dev', $dockerfile);
        self::assertStringNotContainsString('pecl install amqp', $dockerfile);
        self::assertStringNotContainsString('docker-php-ext-enable amqp', $dockerfile);
        self::assertStringContainsString('docker-php-ext-install pdo_pgsql sockets pcntl', $dockerfile);
    }
}
