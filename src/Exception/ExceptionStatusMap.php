<?php

declare(strict_types=1);

namespace App\Exception;

use Fig\Http\Message\StatusCodeInterface;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

/**
 * Single source of truth for translating domain exceptions to transport-level
 * status codes (HTTP and gRPC). Keeps the mapping consistent across the
 * ErrorHandlerMiddleware and the gRPC service.
 */
final readonly class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => StatusCodeInterface::STATUS_BAD_REQUEST,
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => StatusCodeInterface::STATUS_NOT_FOUND,
            $e instanceof RateLimitException => StatusCodeInterface::STATUS_TOO_MANY_REQUESTS,
            default => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
        };
    }

    public function toGrpcStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException => GrpcStatus::INVALID_ARGUMENT,
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
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => $e->getMessage(),
            default => 'Internal server error',
        };
    }
}
