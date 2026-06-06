<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Find;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Domain\SubscriptionNotFoundException;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;

/**
 * Read-side: the create read-back. After SubscribeCommand persists the row (incl.
 * the idempotent re-subscribe branch), the driver asks this to obtain the
 * canonical id + created_at for the 201 body. The row always exists at this
 * point; the not-found guard is defensive.
 *
 * @implements QueryHandler<FindSubscriptionByEmailAndRepositoryQuery, SubscriptionResponse>
 *
 * @psalm-api
 */
final readonly class FindSubscriptionByEmailAndRepositoryHandler implements QueryHandler
{
    public function __construct(private SubscriptionRepository $repository)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        $subscription = $this->repository->findByEmailAndRepository($query->email, $query->repository);
        if ($subscription === null) {
            throw new \RuntimeException(
                "Subscription not found after insert for {$query->email}"
            );
        }

        return SubscriptionResponse::fromAggregate($subscription);
    }
}
