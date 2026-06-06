<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Factory;

use App\Subscription\Subscriptions\Domain\SubscriberRef;

/** @psalm-api */
interface SubscriberRefFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): SubscriberRef;
}
