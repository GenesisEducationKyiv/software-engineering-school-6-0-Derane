<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Exception\RateLimitException;
use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use App\Service\SubscriptionServiceInterface;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionReply;
use Grpc\ReleaseNotifier\V1\DeleteSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\GetSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckRequest;
use Grpc\ReleaseNotifier\V1\HealthCheckResponse;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsReply;
use Grpc\ReleaseNotifier\V1\ListSubscriptionsRequest;
use Grpc\ReleaseNotifier\V1\ReleaseNotifierServiceInterface;
use Grpc\ReleaseNotifier\V1\SubscriptionReply;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\Exception\ServiceException;
use Spiral\RoadRunner\GRPC\StatusCode;

/** @psalm-api */
final class ReleaseNotifierService implements ReleaseNotifierServiceInterface
{
    // gRPC method names are generated from the proto contract and must keep exact casing.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function __construct(
        private SubscriptionServiceInterface $subscriptions,
        private \PDO $pdo,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function Health(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        try {
            $this->pdo->query('SELECT 1');

            return new HealthCheckResponse(['status' => 'ok']);
        } catch (\Throwable $e) {
            $this->logger->error('gRPC health check failed', ['error' => $e->getMessage()]);

            throw ServiceException::create('Service is unhealthy', StatusCode::UNAVAILABLE, $e);
        }
    }

    #[\Override]
    public function CreateSubscription(ContextInterface $ctx, CreateSubscriptionRequest $in): SubscriptionReply
    {
        try {
            return $this->toSubscriptionReply($this->subscriptions->subscribe(
                trim($in->getEmail()),
                trim($in->getRepository())
            ));
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }
    }

    #[\Override]
    public function ListSubscriptions(ContextInterface $ctx, ListSubscriptionsRequest $in): ListSubscriptionsReply
    {
        try {
            $email = trim($in->getEmail());
            $items = $this->subscriptions->listSubscriptions(
                $email !== '' ? $email : null,
                $this->normalizeLimit($in->getLimit()),
                max(0, $in->getOffset())
            );

            return new ListSubscriptionsReply([
                'subscriptions' => array_map($this->toSubscriptionReply(...), $items),
            ]);
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }
    }

    #[\Override]
    public function GetSubscription(ContextInterface $ctx, GetSubscriptionRequest $in): SubscriptionReply
    {
        try {
            return $this->toSubscriptionReply($this->subscriptions->getSubscription($in->getId()));
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }
    }

    #[\Override]
    public function DeleteSubscription(ContextInterface $ctx, DeleteSubscriptionRequest $in): DeleteSubscriptionReply
    {
        try {
            $this->subscriptions->unsubscribe($in->getId());

            return new DeleteSubscriptionReply(['deleted' => true]);
        } catch (\Throwable $e) {
            throw $this->mapException($e);
        }
    }

    /**
     * @param array{id: int, email: string, repository: string, created_at: string} $subscription
     */
    private function toSubscriptionReply(array $subscription): SubscriptionReply
    {
        return new SubscriptionReply([
            'id' => $subscription['id'],
            'email' => $subscription['email'],
            'repository' => $subscription['repository'],
            'created_at' => $subscription['created_at'],
        ]);
    }

    private function normalizeLimit(int $limit): int
    {
        if ($limit <= 0) {
            return 100;
        }

        return min($limit, 100);
    }

    private function mapException(\Throwable $e): GRPCException
    {
        return match (true) {
            $e instanceof ValidationException => GRPCException::create(
                $e->getMessage(),
                StatusCode::INVALID_ARGUMENT,
                $e
            ),
            $e instanceof RepositoryNotFoundException, $e instanceof SubscriptionNotFoundException =>
                GRPCException::create($e->getMessage(), StatusCode::NOT_FOUND, $e),
            $e instanceof RateLimitException => GRPCException::create(
                $e->getMessage(),
                StatusCode::RESOURCE_EXHAUSTED,
                $e
            ),
            default => ServiceException::create('Internal server error', StatusCode::INTERNAL, $e),
        };
    }
    // phpcs:enable
}
