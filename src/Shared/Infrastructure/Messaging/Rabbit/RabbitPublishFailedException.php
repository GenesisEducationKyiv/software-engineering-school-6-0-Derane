<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

/**
 * Raised by {@see RabbitPublisher::publish()} when the broker does not
 * positively confirm a published message — i.e. on a negative confirm
 * (`basic.nack`) or a confirm-wait timeout (AC3).
 *
 * This is the "callers can react" signal epics.md's AC mandates: a publish
 * that returns normally is guaranteed broker-acked; a publish that throws
 * this exception is guaranteed NOT delivered (or its fate unknown after a
 * timeout) and the caller must decide how to recover (retry, log, alert).
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
