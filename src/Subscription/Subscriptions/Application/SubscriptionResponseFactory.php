<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Subscription\Subscriptions\Domain\Subscription;

/** @psalm-api */
final readonly class SubscriptionResponseFactory implements SubscriptionResponseFactoryInterface
{
    #[\Override]
    public function fromAggregate(Subscription $subscription): SubscriptionResponse
    {
        return new SubscriptionResponse(
            (int) $subscription->id(),
            $subscription->email(),
            $subscription->repository(),
            $subscription->createdAt(),
            $subscription->status(),
        );
    }
}
