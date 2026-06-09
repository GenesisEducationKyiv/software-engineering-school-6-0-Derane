<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Error;

use App\Releases\Sourcing\Domain\RateLimitException;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Shared\Domain\Exception\ValidationException;
use App\Subscription\Subscriptions\Domain\SubscriptionNotFoundException;
use Fig\Http\Message\StatusCodeInterface;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

/**
 * Single source of truth for translating domain exceptions to transport-level
 * status codes (HTTP and gRPC). Keeps the mapping consistent across the
 * ErrorHandlerMiddleware and the gRPC service.
 *
 * InvalidArgumentException is the self-validating value objects' rejection
 * (EmailAddress, RepositoryName, ReleaseTag) — client input that fails VO
 * construction is a bad request, same as ValidationException.
 */
final readonly class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException,
            $e instanceof InvalidArgumentException => StatusCodeInterface::STATUS_BAD_REQUEST,
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => StatusCodeInterface::STATUS_NOT_FOUND,
            $e instanceof RateLimitException => StatusCodeInterface::STATUS_TOO_MANY_REQUESTS,
            default => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
        };
    }

    public function toGrpcStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException,
            $e instanceof InvalidArgumentException => GrpcStatus::INVALID_ARGUMENT,
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => GrpcStatus::NOT_FOUND,
            $e instanceof RateLimitException => GrpcStatus::RESOURCE_EXHAUSTED,
            default => GrpcStatus::INTERNAL,
        };
    }

    public function toClientMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof RateLimitException => 'GitHub API rate limit exceeded. Please try again later.',
            $e instanceof ValidationException,
            $e instanceof InvalidArgumentException,
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => $e->getMessage(),
            default => 'Internal server error',
        };
    }
}
