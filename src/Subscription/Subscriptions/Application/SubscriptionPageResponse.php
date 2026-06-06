<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Shared\Domain\Bus\Query\Response;

/**
 * Typed read-model returned across the query bus for a page of subscriptions. The
 * thin driver maps each item to the frozen wire shape (the list endpoint returns
 * a bare JSON array of those items).
 *
 * @psalm-api
 */
final readonly class SubscriptionPageResponse implements Response
{
    /** @param list<SubscriptionResponse> $items */
    public function __construct(public array $items)
    {
    }
}
