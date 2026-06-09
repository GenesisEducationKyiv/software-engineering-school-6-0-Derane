<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class SubscriptionPageResponse implements Response
{
    /** @param list<SubscriptionResponse> $items */
    public function __construct(public array $items)
    {
    }
}
