<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Error;

use App\Sending\Application\WelcomeInFlightException;
use Fig\Http\Message\StatusCodeInterface;
use Spiral\RoadRunner\GRPC\StatusCode as GrpcStatus;

/**
 * Translates exceptions to transport-level status codes (HTTP + gRPC), shared so
 * an exception maps consistently across both sync surfaces.
 *
 * Catch order is LOAD-BEARING: WelcomeRequestValidationException and
 * WelcomeInFlightException both extend \RuntimeException, so their specific arms
 * MUST precede the generic \RuntimeException arm. WelcomeInFlightException maps to
 * ABORTED/409 (benign claim contention, retried next tick); fell through to the
 * \RuntimeException → UNAVAILABLE arm, the client would retry lock contention.
 *
 * \PDOException extends \RuntimeException, so the generic arm already covers
 * transient DB faults — there is deliberately NO separate \PDOException arm (it
 * would be unreachable, and a future transient-vs-constraint split beneath the
 * \RuntimeException arm would silently never fire for PDO errors).
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
