<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Domain;

use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use PHPUnit\Framework\TestCase;

final class SubscriptionTest extends TestCase
{
    public function testSubscribeRecordsExactlyOneSubscriptionCreated(): void
    {
        $subscription = SubscriptionMother::subscribing('user@example.com', 'golang/go');

        $events = $subscription->pullDomainEvents();

        $this->assertCount(1, $events);
        $event = $events[0];
        $this->assertInstanceOf(SubscriptionCreated::class, $event);
        $this->assertSame('user@example.com', $event->email);
        $this->assertSame('golang/go', $event->repository);
        $this->assertSame('subscription.created', $event->eventName());
    }

    public function testPullingTwiceReturnsEmptyOnSecondPull(): void
    {
        $subscription = SubscriptionMother::subscribing();

        $this->assertCount(1, $subscription->pullDomainEvents());
        $this->assertCount(0, $subscription->pullDomainEvents());
    }

    public function testReconstituteRecordsNothing(): void
    {
        $subscription = SubscriptionMother::reconstituted(42);

        $this->assertCount(0, $subscription->pullDomainEvents());
    }

    public function testAccessorsReproduceTheFrozenJsonValues(): void
    {
        $subscription = SubscriptionMother::reconstituted(7, 'a@b.com', 'php/php-src', '2026-04-12T00:00:00Z');

        $this->assertSame(7, $subscription->id());
        $this->assertSame('a@b.com', $subscription->email());
        $this->assertSame('php/php-src', $subscription->repository());
        $this->assertSame('2026-04-12T00:00:00Z', $subscription->createdAt());
    }

    public function testSubscribeLeavesIdNull(): void
    {
        $subscription = SubscriptionMother::subscribing();

        $this->assertNull($subscription->id());
    }
}
