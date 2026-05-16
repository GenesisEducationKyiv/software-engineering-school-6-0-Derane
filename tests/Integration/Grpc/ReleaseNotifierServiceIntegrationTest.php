<?php

declare(strict_types=1);

namespace Tests\Integration\Grpc;

use App\Grpc\ReleaseNotifierService;
use App\Repository\SubscriptionRepository;
use App\Service\GitHubServiceInterface;
use App\Service\SubscriptionService;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\GetSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckRequest;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsRequest;
use Psr\Log\NullLogger;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\StatusCode;
use Tests\Integration\IntegrationTestCase;

final class ReleaseNotifierServiceIntegrationTest extends IntegrationTestCase
{
    private ReleaseNotifierService $service;
    private ContextInterface $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $github = new class implements GitHubServiceInterface {
            public function repositoryExists(string $repository): bool
            {
                return true;
            }

            public function getLatestRelease(string $repository): ?array
            {
                return null;
            }
        };

        $subscriptions = new SubscriptionService(
            new SubscriptionRepository(self::pdo()),
            $github,
            new NullLogger()
        );

        $this->service = new ReleaseNotifierService($subscriptions, self::pdo(), new NullLogger());
        $this->ctx = $this->createMock(ContextInterface::class);
    }

    public function testHealthReturnsOkAgainstRealPdo(): void
    {
        $reply = $this->service->Health($this->ctx, new HealthCheckRequest());

        $this->assertSame('ok', $reply->getStatus());
    }

    public function testCreateSubscriptionPersistsAndReturnsReply(): void
    {
        $reply = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'grpc@example.com',
            'repository' => 'docker/compose',
        ]));

        $this->assertGreaterThan(0, $reply->getId());
        $this->assertSame('grpc@example.com', $reply->getEmail());
        $this->assertSame('docker/compose', $reply->getRepository());
        $this->assertNotEmpty($reply->getCreatedAt());

        $fetched = $this->service->GetSubscription($this->ctx, new GetSubscriptionRequest(['id' => $reply->getId()]));
        $this->assertSame($reply->getId(), $fetched->getId());
    }

    public function testCreateSubscriptionIsIdempotent(): void
    {
        $first = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'dup@example.com',
            'repository' => 'docker/compose',
        ]));

        $second = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'dup@example.com',
            'repository' => 'docker/compose',
        ]));

        $this->assertSame($first->getId(), $second->getId());

        $list = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'email' => 'dup@example.com',
        ]));
        $this->assertCount(1, $list->getSubscriptions());
    }

    public function testCreateSubscriptionWithInvalidEmailMapsToInvalidArgument(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'not-an-email',
            'repository' => 'docker/compose',
        ]));
    }

    public function testCreateSubscriptionWithInvalidRepoFormatMapsToInvalidArgument(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'user@example.com',
            'repository' => 'invalid-repo',
        ]));
    }

    public function testListSubscriptionsReturnsPersistedRows(): void
    {
        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'a@example.com',
            'repository' => 'docker/compose',
        ]));
        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'b@example.com',
            'repository' => 'golang/go',
        ]));

        $reply = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest());

        $this->assertCount(2, $reply->getSubscriptions());
    }

    public function testListSubscriptionsFiltersByEmail(): void
    {
        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'a@example.com',
            'repository' => 'docker/compose',
        ]));
        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'b@example.com',
            'repository' => 'golang/go',
        ]));

        $reply = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'email' => 'a@example.com',
        ]));

        $this->assertCount(1, $reply->getSubscriptions());
        $this->assertSame('a@example.com', $reply->getSubscriptions()[0]->getEmail());
    }

    public function testListSubscriptionsRespectsPagination(): void
    {
        foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $email) {
            $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
                'email' => $email,
                'repository' => 'docker/compose',
            ]));
        }

        $firstPage = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'limit' => 2,
            'offset' => 0,
        ]));
        $secondPage = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'limit' => 2,
            'offset' => 2,
        ]));

        $this->assertCount(2, $firstPage->getSubscriptions());
        $this->assertCount(1, $secondPage->getSubscriptions());
    }

    public function testGetSubscriptionNotFoundMapsToGrpcNotFound(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->service->GetSubscription($this->ctx, new GetSubscriptionRequest(['id' => 999999]));
    }

    public function testDeleteSubscriptionRemovesRow(): void
    {
        $created = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'delete@example.com',
            'repository' => 'docker/compose',
        ]));

        $reply = $this->service->DeleteSubscription($this->ctx, new DeleteSubscriptionRequest([
            'id' => $created->getId(),
        ]));

        $this->assertTrue($reply->getDeleted());

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);
        $this->service->GetSubscription($this->ctx, new GetSubscriptionRequest(['id' => $created->getId()]));
    }

    public function testDeleteSubscriptionMissingMapsToNotFound(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->service->DeleteSubscription($this->ctx, new DeleteSubscriptionRequest(['id' => 999999]));
    }
}
