<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;

/**
 * Object-Mother fixture (CodelyTV style) for the Subscription aggregate. Keeps the
 * two construction paths centralized so tests read intent, not boilerplate.
 */
final class SubscriptionMother
{
    public static function subscribing(
        string $email = 'test@example.com',
        string $repository = 'golang/go',
        string $createdAt = '2026-01-01T00:00:00+00:00'
    ): Subscription {
        return Subscription::subscribe(
            new EmailAddress($email),
            new RepositoryName($repository),
            $createdAt
        );
    }

    public static function reconstituted(
        int $id = 1,
        string $email = 'test@example.com',
        string $repository = 'golang/go',
        string $createdAt = '2026-01-01T00:00:00+00:00',
        string $status = 'pending'
    ): Subscription {
        return Subscription::reconstitute(
            $id,
            new EmailAddress($email),
            new RepositoryName($repository),
            $createdAt,
            $status
        );
    }
}
