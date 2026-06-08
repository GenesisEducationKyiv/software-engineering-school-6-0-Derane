<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class SubscriptionResponse implements Response
{
    public function __construct(
        public int $id,
        public string $email,
        public string $repository,
        public string $createdAt
    ) {
    }
}
