<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

interface SubscriptionCountPort
{
    public function countAll(): int;
}
