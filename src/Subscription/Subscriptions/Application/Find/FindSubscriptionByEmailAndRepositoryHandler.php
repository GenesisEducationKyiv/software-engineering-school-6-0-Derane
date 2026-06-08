<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Find;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactoryInterface;
use App\Subscription\Subscriptions\Domain\SubscriptionNotFoundException;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;

/**
 * @implements QueryHandler<FindSubscriptionByEmailAndRepositoryQuery, SubscriptionResponse>
 * @psalm-api
 */
final readonly class FindSubscriptionByEmailAndRepositoryHandler implements QueryHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private SubscriptionResponseFactoryInterface $responseFactory,
    ) {
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

        return $this->responseFactory->fromAggregate($subscription);
    }
}
