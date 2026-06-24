<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Sync;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Sending\Infrastructure\Sync\NoOpWelcomeOutcomePublisher;
use PHPUnit\Framework\TestCase;

/**
 * Locks the no-op publisher: it must do nothing and never throw, keeping the fail-closed
 * Rabbit reply publisher off the sync send's critical path so a reply-broker outage can
 * never fail a SENT welcome and trigger a false start-sweep compensation.
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
