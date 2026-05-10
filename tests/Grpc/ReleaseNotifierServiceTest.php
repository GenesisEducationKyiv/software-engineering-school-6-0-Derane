<?php

declare(strict_types=1);

namespace Tests\Grpc;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;
use App\Exception\ExceptionStatusMap;
use App\Exception\RepositoryNotFoundException;
use App\Exception\ValidationException;
use App\Grpc\ReleaseNotifierService;
use App\Health\HealthCheckInterface;
use App\Service\SubscriptionServiceInterface;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\GetSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckRequest;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;

class ReleaseNotifierServiceTest extends TestCase
{
    private SubscriptionServiceInterface $subscriptions;
    private HealthCheckInterface $healthCheck;
    private ContextInterface $context;
    private ReleaseNotifierService $service;

    protected function setUp(): void
    {
        $this->subscriptions = $this->createMock(SubscriptionServiceInterface::class);
        $this->healthCheck = $this->createMock(HealthCheckInterface::class);
        $this->context = $this->createMock(ContextInterface::class);

        $this->service = new ReleaseNotifierService(
            $this->subscriptions,
            $this->healthCheck,
            new ExceptionStatusMap(),
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

    public function testCreateSubscriptionReturnsReply(): void
    {
        $this->subscriptions->expects($this->once())
            ->method('subscribe')
            ->with('grpc@example.com', 'docker/compose')
            ->willReturn(new Subscription(7, 'grpc@example.com', 'docker/compose', '2026-04-12T00:00:00Z'));

        $reply = $this->service->CreateSubscription($this->context, new CreateSubscriptionRequest([
            'email' => 'grpc@example.com',
            'repository' => 'docker/compose',
        ]));

        $this->assertSame(7, $reply->getId());
        $this->assertSame('grpc@example.com', $reply->getEmail());
    }

    public function testListSubscriptionsPassesPagination(): void
    {
        $this->subscriptions->expects($this->once())
            ->method('listSubscriptions')
            ->with(
                'grpc@example.com',
                $this->callback(static fn(Pagination $p): bool => $p->limit === 20 && $p->offset === 5)
            )
            ->willReturn(new SubscriptionPage(
                [new Subscription(1, 'grpc@example.com', 'docker/compose', '2026-04-12T00:00:00Z')],
                Pagination::fromRequest(20, 5),
                1
            ));

        $reply = $this->service->ListSubscriptions($this->context, new ListSubscriptionsRequest([
            'email' => 'grpc@example.com',
            'limit' => 20,
            'offset' => 5,
        ]));

        $this->assertCount(1, $reply->getSubscriptions());
        $this->assertSame('docker/compose', $reply->getSubscriptions()[0]->getRepository());
    }

    public function testGetSubscriptionMapsNotFoundToGrpcNotFound(): void
    {
        $this->subscriptions->expects($this->once())
            ->method('getSubscription')
            ->with(999)
            ->willThrowException(new RepositoryNotFoundException('missing/repo'));

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->service->GetSubscription($this->context, new GetSubscriptionRequest(['id' => 999]));
    }

    public function testDeleteSubscriptionReturnsDeletedTrue(): void
    {
        $this->subscriptions->expects($this->once())
            ->method('unsubscribe')
            ->with(5);

        $reply = $this->service->DeleteSubscription($this->context, new DeleteSubscriptionRequest(['id' => 5]));

        $this->assertTrue($reply->getDeleted());
    }

    public function testValidationExceptionMapsToInvalidArgument(): void
    {
        $this->subscriptions->expects($this->once())
            ->method('subscribe')
            ->willThrowException(new ValidationException('bad input'));

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->context, new CreateSubscriptionRequest([
            'email' => 'bad',
            'repository' => 'bad',
        ]));
    }
}
