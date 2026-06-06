<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Find;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class FindSubscriptionByEmailAndRepositoryQuery implements Query
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}
