<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\List;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Subscription\Subscriptions\Application\SubscriptionPageResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactoryInterface;
use App\Subscription\Subscriptions\Domain\SubscriptionPage;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;

/**
 * Read-side: list subscriptions, filtered by email or all, paginated. Delegates
 * to findByEmail / findAll exactly as the legacy listSubscriptions did, mapping
 * the page items to the typed response.
 *
 * @implements QueryHandler<ListSubscriptionsQuery, SubscriptionPageResponse>
 *
 * @psalm-api
 */
final readonly class ListSubscriptionsHandler implements QueryHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private SubscriptionResponseFactoryInterface $responseFactory,
    ) {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        $page = $query->email !== null
            ? $this->repository->findByEmail($query->email, $query->pagination)
            : $this->repository->findAll($query->pagination);

        return new SubscriptionPageResponse($this->mapItems($page));
    }

    /** @return list<SubscriptionResponse> */
    private function mapItems(SubscriptionPage $page): array
    {
        return array_map(
            fn($subscription): SubscriptionResponse => $this->responseFactory->fromAggregate($subscription),
            $page->items
        );
    }
}
