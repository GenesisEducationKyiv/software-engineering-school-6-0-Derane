<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\Subscription;

/** @psalm-api */
final readonly class SubscriptionFactory implements SubscriptionFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): Subscription
    {
        return new Subscription(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['repository'],
            (string) $row['created_at'],
        );
    }
}
