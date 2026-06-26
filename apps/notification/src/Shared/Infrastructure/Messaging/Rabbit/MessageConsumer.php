<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Message\AMQPMessage;

/**
 * Consume + acknowledgement + bounded-retry port over a RabbitMQ work queue.
 *
 * Anti-corruption layers (e.g. SendReleaseEmailConsumer) depend on this
 * abstraction rather than the concrete RabbitConsumer, so their ack/nack/retry/
 * DLQ decision logic can be unit-tested against a doubled port without driving
 * the AMQP protocol.
 */
interface MessageConsumer
{
    /** @param callable(AMQPMessage): void $callback */
    public function consume(string $queue, callable $callback): void;

    public function ack(AMQPMessage $message): void;

    public function nack(AMQPMessage $message, bool $requeue): void;

    public function requeueWithRetry(AMQPMessage $message, string $retryQueue): void;

    public function requeueWithoutRetryIncrement(AMQPMessage $message, string $retryQueue, int $delaySeconds): void;

    public function shouldRouteToDlq(AMQPMessage $message, int $maxRedeliveries): bool;
}
