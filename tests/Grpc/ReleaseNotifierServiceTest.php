<?php

declare(strict_types=1);

namespace Tests\Grpc;

use App\Grpc\ReleaseNotifierService;
use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Shared\Infrastructure\Error\ExceptionStatusMap;
use App\Shared\Infrastructure\Health\HealthCheckInterface;
use App\Shared\Application\Pagination\PaginationFactory;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Domain\ValueObject\Pagination;
use App\Subscription\Subscriptions\Application\Find\FindSubscriptionByIdQuery;
use App\Subscription\Subscriptions\Application\List\ListSubscriptionsQuery;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use App\Subscription\Subscriptions\Application\SubscriptionPageResponse;
use App\Subscription\Subscriptions\Application\SubscriptionResponse;
use App\Subscription\Subscriptions\Application\Unsubscribe\UnsubscribeCommand;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\GetSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckRequest;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

final class ReleaseNotifierServiceTest extends TestCase
{
    private CommandBus&MockObject $commandBus;
    private QueryBus&MockObject $queryBus;
    private HealthCheckInterface&MockObject $healthCheck;
    private ContextInterface&MockObject $context;
    private ReleaseNotifierService $service;

    protected function setUp(): void
    {
        $this->commandBus = $this->createMock(CommandBus::class);
        $this->queryBus = $this->createMock(QueryBus::class);
        $this->healthCheck = $this->createMock(HealthCheckInterface::class);
        $this->context = $this->createMock(ContextInterface::class);

        $this->service = new ReleaseNotifierService(
            $this->commandBus,
            $this->queryBus,
            $this->healthCheck,
            new ExceptionStatusMap(),
            new PaginationFactory(),
            new NullLogger()
        );
    }

    public function testHealthReturnsOk(): void
    {
        $this->healthCheck->expects($this->once())->method('check');

        $reply = $this->service->Health($this->context, new HealthCheckRequest());

        $this->assertSame('ok', $reply->getStatus());
    }

    public function testHealthThrowsUnavailableWhenDatabaseFails(): void
    {
        $this->healthCheck->method('check')->willThrowException(new \RuntimeException('db down'));

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::UNAVAILABLE);

        $this->service->Health($this->context, new HealthCheckRequest());
    }

    public function testCreateSubscriptionDispatchesCommandThenReadsBack(): void
    {
        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn(Command $c): bool =>
                $c instanceof SubscribeCommand
                && $c->email === 'grpc@example.com'
                && $c->repository === 'docker/compose'));

        $this->queryBus->expects($this->once())
            ->method('ask')
            ->willReturn(
                new SubscriptionResponse(7, 'grpc@example.com', 'docker/compose', '2026-04-12T00:00:00Z', 'pending')
            );

        $reply = $this->service->CreateSubscription($this->context, new CreateSubscriptionRequest([
            'email' => 'grpc@example.com',
            'repository' => 'docker/compose',
        ]));

        $this->assertSame(7, $reply->getId());
        $this->assertSame('grpc@example.com', $reply->getEmail());
        $this->assertSame('docker/compose', $reply->getRepository());
        $this->assertSame('2026-04-12T00:00:00Z', $reply->getCreatedAt());
    }

    public function testListSubscriptionsPassesPagination(): void
    {
        $this->queryBus->expects($this->once())
            ->method('ask')
            ->with($this->callback(static fn(Query $q): bool =>
                $q instanceof ListSubscriptionsQuery
                && $q->email === 'grpc@example.com'
                && $q->pagination instanceof Pagination
                && $q->pagination->limit === 20
                && $q->pagination->offset === 5))
            ->willReturn(new SubscriptionPageResponse([
                new SubscriptionResponse(1, 'grpc@example.com', 'docker/compose', '2026-04-12T00:00:00Z', 'pending'),
            ]));

        $reply = $this->service->ListSubscriptions($this->context, new ListSubscriptionsRequest([
            'email' => 'grpc@example.com',
            'limit' => 20,
            'offset' => 5,
        ]));

        $this->assertCount(1, $reply->getSubscriptions());
        $this->assertSame('docker/compose', $reply->getSubscriptions()[0]->getRepository());
    }

    public function testGetSubscriptionAsksByIdAndMapsReply(): void
    {
        $this->queryBus->expects($this->once())
            ->method('ask')
            ->with($this->callback(static fn(Query $q): bool =>
                $q instanceof FindSubscriptionByIdQuery && $q->id === 4))
            ->willReturn(new SubscriptionResponse(4, 'g@h.com', 'docker/compose', '2026-04-12T00:00:00Z', 'pending'));

        $reply = $this->service->GetSubscription($this->context, new GetSubscriptionRequest(['id' => 4]));

        $this->assertSame(4, $reply->getId());
    }

    public function testGetSubscriptionMapsNotFoundToGrpcNotFound(): void
    {
        $this->queryBus->expects($this->once())
            ->method('ask')
            ->willThrowException(new RepositoryNotFoundException('missing/repo'));

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->service->GetSubscription($this->context, new GetSubscriptionRequest(['id' => 999]));
    }

    public function testDeleteSubscriptionReturnsDeletedTrue(): void
    {
        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->with($this->callback(static fn(Command $c): bool =>
                $c instanceof UnsubscribeCommand && $c->id === 5));

        $reply = $this->service->DeleteSubscription($this->context, new DeleteSubscriptionRequest(['id' => 5]));

        $this->assertTrue($reply->getDeleted());
    }

    public function testValidationExceptionMapsToInvalidArgument(): void
    {
        $this->commandBus->expects($this->once())
            ->method('dispatch')
            ->willThrowException(new ValidationException('bad input'));

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->context, new CreateSubscriptionRequest([
            'email' => 'bad',
            'repository' => 'bad',
        ]));
    }
}
