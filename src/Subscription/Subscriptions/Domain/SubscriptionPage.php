<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\ValueObject\Pagination;

/** @psalm-api */
final readonly class SubscriptionPage
{
    /** @param list<Subscription> $items */
    public function __construct(
        public array $items,
        public Pagination $pagination,
        public int $total
    ) {
    }

    public function hasNextPage(): bool
    {
        return $this->pagination->offset + count($this->items) < $this->total;
    }
}
