<?php

declare(strict_types=1);

namespace App\Grpc;

use App\Config\Factory\PaginationFactoryInterface;
use App\Domain\Subscription;
use App\Exception\ExceptionStatusMap;
use App\Health\HealthCheckInterface;
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
final readonly class ReleaseNotifierService implements ReleaseNotifierServiceInterface
{
    // gRPC method names are generated from the proto contract and must keep exact casing.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps
    public function __construct(
        private SubscriptionServiceInterface $subscriptions,
        private HealthCheckInterface $healthCheck,
        private ExceptionStatusMap $statusMap,
        private PaginationFactoryInterface $paginationFactory,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function Health(ContextInterface $ctx, HealthCheckRequest $in): HealthCheckResponse
    {
        try {
            $this->healthCheck->check();

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
            $page = $this->subscriptions->listSubscriptions(
                $email !== '' ? $email : null,
                $this->paginationFactory->fromRequest($in->getLimit(), $in->getOffset())
            );

            return new ListSubscriptionsReply([
                'subscriptions' => array_map($this->toSubscriptionReply(...), $page->items),
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

    private function toSubscriptionReply(Subscription $subscription): SubscriptionReply
    {
        return new SubscriptionReply([
            'id' => $subscription->id,
            'email' => $subscription->email,
            'repository' => $subscription->repository,
            'created_at' => $subscription->createdAt,
        ]);
    }

    private function mapException(\Throwable $e): GRPCException
    {
        /**
         * @var 0|1|2|3|4|5|6|7|8|9|10|11|12|13|14|15|16 $code
         */
        $code = $this->statusMap->toGrpcStatus($e);
        $message = $this->statusMap->toClientMessage($e);

        if ($code === StatusCode::INTERNAL) {
            return ServiceException::create($message, $code, $e);
        }

        return GRPCException::create($message, $code, $e);
    }
    // phpcs:enable
}
