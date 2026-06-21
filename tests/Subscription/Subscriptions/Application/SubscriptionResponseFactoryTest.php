<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application;

use App\Subscription\Subscriptions\Application\SubscriptionResponseFactory;
use PHPUnit\Framework\TestCase;
use Tests\Subscription\Subscriptions\Domain\SubscriptionMother;

/**
 * fromAggregate() now threads status() into the response so Epic E has it to
 * serialize; the status field stays carried-but-not-yet-on-the-wire this epic.
 */
final class SubscriptionResponseFactoryTest extends TestCase
{
    public function testFromAggregateCarriesStatus(): void
    {
        $factory = new SubscriptionResponseFactory();

        $response = $factory->fromAggregate(
            SubscriptionMother::reconstituted(7, 'a@b.com', 'php/php-src', '2026-04-12T00:00:00Z', 'confirmed')
        );

        $this->assertSame(7, $response->id);
        $this->assertSame('a@b.com', $response->email);
        $this->assertSame('php/php-src', $response->repository);
        $this->assertSame('2026-04-12T00:00:00Z', $response->createdAt);
        $this->assertSame('confirmed', $response->status);
    }

    public function testFromAggregateDefaultsCreatePathToPending(): void
    {
        $factory = new SubscriptionResponseFactory();

        $response = $factory->fromAggregate(SubscriptionMother::subscribing());

        $this->assertSame('pending', $response->status);
    }
}
