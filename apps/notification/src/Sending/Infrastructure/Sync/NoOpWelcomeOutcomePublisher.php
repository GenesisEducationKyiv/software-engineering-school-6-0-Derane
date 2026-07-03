<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Sync;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;

/**
 * No-op outcome publisher bound on the sync welcome surfaces: the relay already blocks for
 * and applies the outcome in-thread, so the Rabbit reply must be kept OFF the send's critical
 * path — its fail-closed publish would map a successfully-sent welcome to 503, exhaust the
 * relay's retry budget, and let the start-sweep false-compensate (cancel) the subscription.
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
    }
}
