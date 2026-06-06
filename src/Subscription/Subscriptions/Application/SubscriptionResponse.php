<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application;

use App\Shared\Domain\Bus\Query\Response;
use App\Subscription\Subscriptions\Domain\Subscription;

/**
 * Typed read-model returned across the query bus for a single subscription. The
 * thin driver (HTTP / gRPC) maps it to the frozen wire shape. Keeps the bus
 * boundary free of mixed and off the aggregate (which holds VOs).
 *
 * @psalm-api
 */
final readonly class SubscriptionResponse implements Response
{
    public function __construct(
        public int $id,
        public string $email,
        public string $repository,
        public string $createdAt
    ) {
    }

    /**
     * Maps a persisted (id-bearing) aggregate to its response. The id is
     * guaranteed non-null on the reconstitution path the query handlers use.
     */
    public static function fromAggregate(Subscription $subscription): self
    {
        return new self(
            (int) $subscription->id(),
            $subscription->email(),
            $subscription->repository(),
            $subscription->createdAt()
        );
    }
}
