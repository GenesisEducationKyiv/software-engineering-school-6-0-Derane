<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Publishes a welcome-email outcome reply back to the monolith orchestrator.
 * Contract: the reply is durably accepted by the broker or this throws — the
 * caller must not ack/nack as if it succeeded on a throw.
 */
interface WelcomeOutcomePublisher
{
    public function publish(
        string $sagaId,
        int $subscriptionId,
        WelcomeOutcome $outcome,
        ?string $error = null,
    ): void;
}
