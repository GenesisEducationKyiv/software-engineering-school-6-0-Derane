<?php

declare(strict_types=1);

namespace Tests\Integration\Grpc;

use App\Grpc\ReleaseNotifierService;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\GetSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckRequest;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsRequest;
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
        $this->service = $this->c->get(ReleaseNotifierService::class);
        $this->ctx = $this->createMock(ContextInterface::class);
    }

    public function testHealthReturnsOkAgainstRealPdo(): void
    {
        $reply = $this->service->Health($this->ctx, new HealthCheckRequest());

        $this->assertSame('ok', $reply->getStatus());
    }

    public function testCreateSubscriptionPersistsAndReturnsReply(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $reply = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $email,
            'repository' => $repository,
        ]));

        $this->assertGreaterThan(0, $reply->getId());
        $this->assertSame($email, $reply->getEmail());
        $this->assertSame($repository, $reply->getRepository());
        $this->assertNotEmpty($reply->getCreatedAt());

        $fetched = $this->service->GetSubscription(
            $this->ctx,
            new GetSubscriptionRequest(['id' => $reply->getId()])
        );
        $this->assertSame($reply->getId(), $fetched->getId());
    }

    public function testCreateSubscriptionIsIdempotent(): void
    {
        $email = $this->faker->safeEmail();
        $repository = $this->repoName();

        $first = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $email,
            'repository' => $repository,
        ]));

        $second = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $email,
            'repository' => $repository,
        ]));

        $this->assertSame($first->getId(), $second->getId());

        $list = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'email' => $email,
        ]));
        $this->assertCount(1, $list->getSubscriptions());
    }

    public function testCreateSubscriptionWithInvalidEmailMapsToInvalidArgument(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => 'not-an-email',
            'repository' => $this->repoName(),
        ]));
    }

    public function testCreateSubscriptionWithInvalidRepoFormatMapsToInvalidArgument(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::INVALID_ARGUMENT);

        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $this->faker->safeEmail(),
            'repository' => 'invalid-repo',
        ]));
    }

    public function testListSubscriptionsReturnsPersistedRows(): void
    {
        $this->createSubscription();
        $this->createSubscription();

        $reply = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest());

        $this->assertCount(2, $reply->getSubscriptions());
    }

    public function testListSubscriptionsFiltersByEmail(): void
    {
        $mine = $this->faker->safeEmail();
        $this->createSubscription($mine);
        $this->createSubscription();

        $reply = $this->service->ListSubscriptions($this->ctx, new ListSubscriptionsRequest([
            'email' => $mine,
        ]));

        $this->assertCount(1, $reply->getSubscriptions());
        $this->assertSame($mine, $reply->getSubscriptions()[0]->getEmail());
    }

    public function testListSubscriptionsRespectsPagination(): void
    {
        $this->createSubscription();
        $this->createSubscription();
        $this->createSubscription();

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

        $this->service->GetSubscription(
            $this->ctx,
            new GetSubscriptionRequest(['id' => $this->faker->numberBetween(1_000_000, 9_999_999)])
        );
    }

    public function testDeleteSubscriptionRemovesRow(): void
    {
        $created = $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $this->faker->safeEmail(),
            'repository' => $this->repoName(),
        ]));

        $reply = $this->service->DeleteSubscription($this->ctx, new DeleteSubscriptionRequest([
            'id' => $created->getId(),
        ]));

        $this->assertTrue($reply->getDeleted());

        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);
        $this->service->GetSubscription(
            $this->ctx,
            new GetSubscriptionRequest(['id' => $created->getId()])
        );
    }

    public function testDeleteSubscriptionMissingMapsToNotFound(): void
    {
        $this->expectException(GRPCException::class);
        $this->expectExceptionCode(StatusCode::NOT_FOUND);

        $this->service->DeleteSubscription(
            $this->ctx,
            new DeleteSubscriptionRequest(['id' => $this->faker->numberBetween(1_000_000, 9_999_999)])
        );
    }

    private function createSubscription(?string $email = null): void
    {
        $this->service->CreateSubscription($this->ctx, new CreateSubscriptionRequest([
            'email' => $email ?? $this->faker->safeEmail(),
            'repository' => $this->repoName(),
        ]));
    }

    private function repoName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
