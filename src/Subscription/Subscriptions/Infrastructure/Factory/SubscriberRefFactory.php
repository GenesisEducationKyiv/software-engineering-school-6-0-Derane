<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Factory;

use App\Subscription\Subscriptions\Domain\SubscriberRef;

/** @psalm-api */
final readonly class SubscriberRefFactory implements SubscriberRefFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): SubscriberRef
    {
        return new SubscriberRef((int) $row['id'], (string) $row['email']);
    }
}
