<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\Find;

use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByEmailAndRepositoryQuery;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactory;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Subscription\Subscriptions\Domain\SubscriptionMother;

final class FindSubscriptionByEmailAndRepositoryHandlerTest extends TestCase
{
    private SubscriptionRepository&MockObject $repository;
    private FindSubscriptionByEmailAndRepositoryHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->handler = new FindSubscriptionByEmailAndRepositoryHandler(
            $this->repository,
            new SubscriptionResponseFactory(),
        );
    }

    public function testReturnsResponseForExistingRow(): void
    {
        $this->repository->expects($this->once())
            ->method('findByEmailAndRepository')
            ->with('a@b.com', 'golang/go')
            ->willReturn(SubscriptionMother::reconstituted(5, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z'));

        $response = ($this->handler)(new FindSubscriptionByEmailAndRepositoryQuery('a@b.com', 'golang/go'));

        $this->assertInstanceOf(SubscriptionResponse::class, $response);
        $this->assertSame(5, $response->id);
    }

    public function testThrowsWhenRowMissingAfterInsert(): void
    {
        $this->repository->expects($this->once())
            ->method('findByEmailAndRepository')
            ->with('a@b.com', 'golang/go')
            ->willReturn(null);

        $this->expectException(\RuntimeException::class);

        ($this->handler)(new FindSubscriptionByEmailAndRepositoryQuery('a@b.com', 'golang/go'));
    }
}
