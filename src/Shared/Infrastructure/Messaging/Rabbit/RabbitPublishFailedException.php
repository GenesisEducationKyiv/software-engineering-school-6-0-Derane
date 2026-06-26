<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

/**
 * Raised by RabbitPublisher::publish() on broker nack or confirm-wait timeout.
 * A publish that returns normally is guaranteed broker-acked; a throw means
 * the message was NOT confirmed and the caller must decide how to recover.
 */
final class RabbitPublishFailedException extends \RuntimeException
{
    public static function nacked(string $exchange, string $routingKey): self
    {
        return new self(sprintf(
            'RabbitMQ broker negatively acknowledged (nack) the message published'
                . ' to exchange "%s" with routing key "%s".',
            $exchange,
            $routingKey
        ));
    }

    public static function confirmTimedOut(string $exchange, string $routingKey, float $timeoutSeconds): self
    {
        return new self(sprintf(
            'Timed out after %.1fs waiting for the RabbitMQ broker to confirm the message published'
                . ' to exchange "%s" with routing key "%s".',
            $timeoutSeconds,
            $exchange,
            $routingKey
        ));
    }
}
