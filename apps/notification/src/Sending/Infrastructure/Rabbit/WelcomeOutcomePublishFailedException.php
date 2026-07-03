<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

/**
 * Thrown instead of treating the reply as published so the caller fails closed:
 * the delivery is left unacked for redelivery and the process restarts. Safe
 * because redelivery re-emits from the ledger's sent state without re-sending.
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
