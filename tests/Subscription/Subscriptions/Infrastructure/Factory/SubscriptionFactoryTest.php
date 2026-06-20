<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Infrastructure\Factory;

use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactory;
use PHPUnit\Framework\TestCase;

/**
 * The factory maps every row column the projection now carries — including the
 * B4 `status` column — onto the reconstituted aggregate.
 */
final class SubscriptionFactoryTest extends TestCase
{
    public function testReconstituteMapsStatusFromTheRow(): void
    {
        $factory = new SubscriptionFactory();

        $subscription = $factory->reconstitute([
            'id' => '42',
            'email' => 'user@example.com',
            'repository' => 'golang/go',
            'created_at' => '2026-04-12T00:00:00Z',
            'status' => 'confirmed',
        ]);

        $this->assertSame(42, $subscription->id());
        $this->assertSame('user@example.com', $subscription->email());
        $this->assertSame('golang/go', $subscription->repository());
        $this->assertSame('2026-04-12T00:00:00Z', $subscription->createdAt());
        $this->assertSame('confirmed', $subscription->status());
    }
}
