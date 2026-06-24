<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Sync;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;

/**
 * The {@see WelcomeOutcomePublisher} bound on the SYNCHRONOUS welcome surfaces (the
 * REST {@see \App\Sending\Infrastructure\Http\WelcomeEmailController} and the gRPC
 * {@see \App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService}). It deliberately
 * does nothing.
 *
 * On the sync transports the monolith saga relay BLOCKS for the sent|failed outcome
 * and applies it in-thread (HandleWelcomeEmailOutcomeCommand) — so the async
 * RabbitMQ reply is not just redundant, it must be kept OFF the send's critical path:
 * the fail-closed {@see \App\Sending\Infrastructure\Rabbit\RabbitWelcomeOutcomePublisher}
 * throws on an unconfirmed publish, which (via {@see \App\Sending\Infrastructure\Error\ExceptionStatusMap})
 * maps a SUCCESSFULLY-sent welcome to UNAVAILABLE/503. The relay then exhausts its
 * retry budget and leaves the saga `Started`, where the start-sweep eventually
 * false-compensates it — cancelling a subscription whose welcome email was actually
 * sent. Binding this no-op on the sync surfaces removes the reply broker from the sync
 * send leg entirely, matching the migration's intent that the sync path REPLACES the
 * broker buffer (arch §7.4 / ADR-0004). The async consumer keeps the real Rabbit
 * publisher, so the rabbit transport is unchanged.
 */
final readonly class NoOpWelcomeOutcomePublisher implements WelcomeOutcomePublisher
{
    #[\Override]
    public function publish(
        string $sagaId,
        int $subscriptionId,
        WelcomeOutcome $outcome,
        ?string $error = null,
    ): void {
        // Intentionally a no-op: the sync relay already applied the outcome in-thread.
    }
}
