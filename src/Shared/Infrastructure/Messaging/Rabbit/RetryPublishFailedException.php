<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

/**
 * The retry/park republish could not be confirmed by the broker (confirm-wait
 * timeout, broker nack, or no live connection). It is thrown INSTEAD of acking
 * the original delivery, so the original stays unacked and is redelivered; the
 * consumer process then exits for a supervised restart with a fresh connection.
 * Distinct from AMQPTimeoutException so the consumer poll loop cannot mistake a
 * failed republish for an idle poll.
 */
final class RetryPublishFailedException extends \RuntimeException
{
    public static function confirmTimedOut(string $queue, float $timeoutSeconds, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('Retry publish to "%s" was not confirmed within %.1fs', $queue, $timeoutSeconds),
            previous: $previous,
        );
    }

    public static function brokerNacked(string $queue): self
    {
        return new self(sprintf('Broker nacked the retry publish to "%s"', $queue));
    }

    public static function noConnection(string $queue): self
    {
        return new self(sprintf('No live AMQP connection to publish the retry copy to "%s"', $queue));
    }
}
