<?php

declare(strict_types=1);

namespace App\Sending\Domain;

/**
 * Publishes a `WelcomeEmailOutcome` reply back to the monolith orchestrator —
 * the service's first outbound integration message. Every processed welcome
 * emits exactly one reply: `Sent` on success/dedup, `Failed` on terminal
 * failure (FR7). The Rabbit adapter ({@see \App\Sending\Infrastructure\Rabbit\RabbitWelcomeOutcomePublisher},
 * C5) publishes with publisher confirms and fails closed on an unconfirmed
 * publish, so the port's contract is "the reply is durably accepted by the broker
 * or this throws" — the caller must not ack/nack as if it succeeded on a throw.
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
