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
 * @implements QueryHandler<FindSubscriptionByIdQuery, SubscriptionResponse>
 * @psalm-api
 */
final readonly class FindSubscriptionByIdHandler implements QueryHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private SubscriptionResponseFactoryInterface $responseFactory,
    ) {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        $subscription = $this->repository->findById($query->id);
        if ($subscription === null) {
            throw new SubscriptionNotFoundException($query->id);
        }

        return $this->responseFactory->fromAggregate($subscription);
    }
}
