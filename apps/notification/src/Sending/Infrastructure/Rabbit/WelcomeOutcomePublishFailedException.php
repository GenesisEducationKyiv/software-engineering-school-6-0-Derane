<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * The `WelcomeEmailOutcome` reply could not be confirmed by the broker
 * (confirm-wait timeout, broker nack, or no live connection). Thrown INSTEAD of
 * treating the reply as published, so the caller fails closed: the delivery is
 * left unacked for redelivery and the process exits for a supervised restart. On
 * redelivery the welcome ledger's terminal/sent state re-emits the reply without
 * re-sending the email (FR7 idempotency).
 */
final class WelcomeOutcomePublishFailedException extends \RuntimeException
{
    public static function noConnection(): self
    {
        return new self('No live AMQP connection to publish the WelcomeEmailOutcome reply');
    }

    public static function brokerNacked(): self
    {
        return new self('Broker nacked the WelcomeEmailOutcome reply publish');
    }

    public static function confirmTimedOut(float $timeoutSeconds, ?\Throwable $previous = null): self
    {
        return new self(
            sprintf('WelcomeEmailOutcome reply was not confirmed within %.1fs', $timeoutSeconds),
            previous: $previous,
        );
    }
}
