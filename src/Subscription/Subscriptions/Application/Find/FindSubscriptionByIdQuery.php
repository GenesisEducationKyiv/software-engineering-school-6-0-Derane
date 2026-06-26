<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Find;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class FindSubscriptionByIdQuery implements Query
{
    public function __construct(public int $id)
    {
    }
}
