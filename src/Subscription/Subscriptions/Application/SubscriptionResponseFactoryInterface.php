<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Subscription\Subscriptions\Domain\Subscription;

interface SubscriptionResponseFactoryInterface
{
    public function fromAggregate(Subscription $subscription): SubscriptionResponse;
}
