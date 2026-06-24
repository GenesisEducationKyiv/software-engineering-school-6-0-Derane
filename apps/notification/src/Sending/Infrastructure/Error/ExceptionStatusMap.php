<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Error;

use App\Sending\Application\WelcomeInFlightException;
use Fig\Http\Message\StatusCodeInterface;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

/**
 * Single source of truth for translating exceptions to transport-level status
 * codes on the notification service — HTTP (the REST baseline + the
 * {@see \App\Sending\Infrastructure\Http\ErrorHandlerMiddleware}) and gRPC (the
 * {@see \App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService}). Both sync
 * surfaces share it so an exception maps consistently across transports (FR6).
 *
 * The catch order is LOAD-BEARING (RD6): {@see WelcomeRequestValidationException}
 * and {@see WelcomeInFlightException} both extend \RuntimeException, so their
 * specific arms MUST precede the generic \RuntimeException arm. In particular,
 * WelcomeInFlightException maps to ABORTED/409 (benign claim contention — the
 * caller leaves the saga pending and retries next tick); if it fell through to the
 * \RuntimeException → UNAVAILABLE arm, the client would retry lock contention.
 *
 * Note: {@see \App\Sending\Application\WelcomeAlreadyFailedException} is NOT a status
 * arm — it is a business FAILED outcome the transport adapter returns as a normal
 * OK response before ever reaching this map.
 *
 * \PDOException extends \RuntimeException, so the generic \RuntimeException arm already
 * covers transient DB faults — there is deliberately NO separate \PDOException arm. One
 * would be unreachable, and (worse) any future transient-vs-constraint split added beneath
 * the \RuntimeException arm would silently never fire for PDO errors.
 *
 * The 401/403 (auth) rows are reserved: v1 has no auth (the sync surfaces are
 * reachable only on the compose network).
 */
final readonly class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof WelcomeRequestValidationException => StatusCodeInterface::STATUS_BAD_REQUEST,
            $e instanceof WelcomeInFlightException => StatusCodeInterface::STATUS_CONFLICT,
            $e instanceof \RuntimeException => StatusCodeInterface::STATUS_SERVICE_UNAVAILABLE,
            default => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
        };
    }

    public function toGrpcStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof WelcomeRequestValidationException => GrpcStatus::INVALID_ARGUMENT,
            $e instanceof WelcomeInFlightException => GrpcStatus::ABORTED,
            $e instanceof \RuntimeException => GrpcStatus::UNAVAILABLE,
            default => GrpcStatus::INTERNAL,
        };
    }

    public function toClientMessage(\Throwable $e): string
    {
        return match (true) {
            $e instanceof WelcomeRequestValidationException,
            $e instanceof WelcomeInFlightException => $e->getMessage(),
            $e instanceof \RuntimeException => 'Service unavailable',
            default => 'Internal server error',
        };
    }
}
