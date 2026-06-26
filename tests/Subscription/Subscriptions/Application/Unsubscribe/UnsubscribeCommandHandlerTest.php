<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Application\Unsubscribe;

use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommandHandler;
use App\Subscription\Subscriptions\Application\Exception\SubscriptionNotFoundException;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Subscription\Subscriptions\Domain\SubscriptionMother;

final class UnsubscribeCommandHandlerTest extends TestCase
{
    private SubscriptionRepository&MockObject $repository;
    private UnsubscribeCommandHandler $handler;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(SubscriptionRepository::class);
        $this->handler = new UnsubscribeCommandHandler($this->repository, new NullLogger());
    }

    public function testDeletesExistingSubscription(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(1)
            ->willReturn(SubscriptionMother::reconstituted(1));

        $this->repository->expects($this->once())
            ->method('delete')
            ->with(1);

        ($this->handler)(new UnsubscribeCommand(1));
    }

    public function testThrowsNotFoundWhenMissing(): void
    {
        $this->repository->expects($this->once())
            ->method('findById')
            ->with(999)
            ->willReturn(null);
        $this->repository->expects($this->never())->method('delete');

        $this->expectException(SubscriptionNotFoundException::class);

        ($this->handler)(new UnsubscribeCommand(999));
    }
}
