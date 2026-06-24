<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Sync;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Infrastructure\Sync\NoOpWelcomeOutcomePublisher;
use PHPUnit\Framework\TestCase;

/**
 * The sync welcome surfaces (REST + gRPC) bind this no-op publisher so the fail-closed
 * Rabbit reply publisher stays off the sync send's critical path (ADR-0004): the relay
 * applies the outcome in-thread, so a reply-broker outage can never fail a SENT welcome
 * and trigger a false start-sweep compensation. That guarantee depends on this publisher
 * doing nothing and never throwing — which these tests lock.
 */
final class NoOpWelcomeOutcomePublisherTest extends TestCase
{
    public function testItIsAWelcomeOutcomePublisher(): void
    {
        self::assertInstanceOf(WelcomeOutcomePublisher::class, new NoOpWelcomeOutcomePublisher());
    }

    public function testPublishingASentOutcomeIsANoOpThatNeverThrows(): void
    {
        $this->expectNotToPerformAssertions();

        (new NoOpWelcomeOutcomePublisher())->publish('saga-1', 42, WelcomeOutcome::Sent);
    }

    public function testPublishingAFailedOutcomeWithErrorIsANoOpThatNeverThrows(): void
    {
        $this->expectNotToPerformAssertions();

        (new NoOpWelcomeOutcomePublisher())->publish('saga-1', 42, WelcomeOutcome::Failed, 'terminal failure');
    }
}
