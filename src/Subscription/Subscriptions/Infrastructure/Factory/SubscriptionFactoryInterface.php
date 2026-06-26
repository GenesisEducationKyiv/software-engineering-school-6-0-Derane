<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Factory;

use App\Subscription\Subscriptions\Domain\Subscription;

/** @psalm-api */
interface SubscriptionFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function reconstitute(array $row): Subscription;
}
