<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\List;

use App\Shared\Domain\ValueObject\Pagination;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsHandler;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\SubscriptionPageResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactory;
use App\Subscription\Subscriptions\Domain\SubscriptionPage;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Subscription\Subscriptions\Domain\SubscriptionMother;

final class ListSubscriptionsHandlerTest extends TestCase
{
    private SubscriptionRepository&MockObject $repository;
    private ListSubscriptionsHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->handler = new ListSubscriptionsHandler($this->repository, new SubscriptionResponseFactory());
    }

    public function testListByEmailDelegatesToFindByEmail(): void
    {
        $pagination = new Pagination(100, 0);
        $this->repository->expects($this->once())
            ->method('findByEmail')
            ->with('a@b.com', $pagination)
            ->willReturn(new SubscriptionPage(
                [SubscriptionMother::reconstituted(1, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z')],
                $pagination,
                1
            ));
        $this->repository->expects($this->never())->method('findAll');

        $response = ($this->handler)(new ListSubscriptionsQuery('a@b.com', $pagination));

        $this->assertInstanceOf(SubscriptionPageResponse::class, $response);
        $this->assertCount(1, $response->items);
        $this->assertSame('golang/go', $response->items[0]->repository);
    }

    public function testListAllDelegatesToFindAll(): void
    {
        $pagination = new Pagination(100, 0);
        $this->repository->expects($this->once())
            ->method('findAll')
            ->with($pagination)
            ->willReturn(new SubscriptionPage(
                [
                    SubscriptionMother::reconstituted(1, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z'),
                    SubscriptionMother::reconstituted(2, 'c@d.com', 'php/php-src', '2024-01-01T00:00:00Z'),
                ],
                $pagination,
                2
            ));
        $this->repository->expects($this->never())->method('findByEmail');

        $response = ($this->handler)(new ListSubscriptionsQuery(null, $pagination));

        $this->assertCount(2, $response->items);
    }
}
