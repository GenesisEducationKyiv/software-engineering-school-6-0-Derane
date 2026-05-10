<?php

declare(strict_types=1);

namespace App\Domain;

use App\Config\Pagination;

/** @psalm-api */
final class SubscriptionPage
{
    /** @param list<Subscription> $items */
    public function __construct(
        public readonly array $items,
        public readonly Pagination $pagination,
        public readonly int $total
    ) {
    }

    public function hasNextPage(): bool
    {
        return $this->pagination->offset + count($this->items) < $this->total;
    }
}
