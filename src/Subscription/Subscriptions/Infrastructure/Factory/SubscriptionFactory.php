<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Factory;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;

/** @psalm-api */
final readonly class SubscriptionFactory implements SubscriptionFactoryInterface
{
    #[\Override]
    public function reconstitute(array $row): Subscription
    {
        return Subscription::reconstitute(
            (int) $row['id'],
            new EmailAddress((string) $row['email']),
            new RepositoryName((string) $row['repository']),
            (string) $row['created_at'],
            (string) $row['status'],
        );
    }
}
