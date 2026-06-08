<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\Find;

use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdHandler;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponseFactory;
use App\Subscription\Subscriptions\Domain\SubscriptionNotFoundException;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Tests\Subscription\Subscriptions\Domain\SubscriptionMother;

final class FindSubscriptionByIdHandlerTest extends TestCase
{
    private SubscriptionRepository&MockObject $repository;
    private FindSubscriptionByIdHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->handler = new FindSubscriptionByIdHandler($this->repository, new SubscriptionResponseFactory());
    }

    public function testReturnsResponseForExistingSubscription(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(SubscriptionMother::reconstituted(1, 'a@b.com', 'golang/go', '2024-01-01T00:00:00Z'));

        $response = ($this->handler)(new FindSubscriptionByIdQuery(1));

        $this->assertInstanceOf(SubscriptionResponse::class, $response);
        $this->assertSame(1, $response->id);
        $this->assertSame('a@b.com', $response->email);
        $this->assertSame('golang/go', $response->repository);
        $this->assertSame('2024-01-01T00:00:00Z', $response->createdAt);
    }

    public function testThrowsNotFoundWhenMissing(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);

        $this->expectException(SubscriptionNotFoundException::class);

        ($this->handler)(new FindSubscriptionByIdQuery(999));
    }
}
