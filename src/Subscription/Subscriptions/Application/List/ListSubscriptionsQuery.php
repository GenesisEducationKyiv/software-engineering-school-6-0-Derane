<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\List;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\ValueObject\Pagination;

/** @psalm-api */
final readonly class ListSubscriptionsQuery implements Query
{
    public function __construct(
        public ?string $email,
        public Pagination $pagination
    ) {
    }
}
