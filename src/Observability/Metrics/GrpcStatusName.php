<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * Single source of truth for the gRPC StatusCode int -> canonical name mapping.
 * Shared by the metric label ({@see PrometheusGrpcMetrics}) and the per-call
 * access log ({@see MeasuredInvoker}) so both report the same code string.
 */
final readonly class GrpcStatusName
{
    public function of(int $code): string
    {
        return match ($code) {
            StatusCode::OK => 'OK',
            StatusCode::CANCELLED => 'CANCELLED',
            StatusCode::UNKNOWN => 'UNKNOWN',
            StatusCode::INVALID_ARGUMENT => 'INVALID_ARGUMENT',
            StatusCode::DEADLINE_EXCEEDED => 'DEADLINE_EXCEEDED',
            StatusCode::NOT_FOUND => 'NOT_FOUND',
            StatusCode::ALREADY_EXISTS => 'ALREADY_EXISTS',
            StatusCode::PERMISSION_DENIED => 'PERMISSION_DENIED',
            StatusCode::RESOURCE_EXHAUSTED => 'RESOURCE_EXHAUSTED',
            StatusCode::FAILED_PRECONDITION => 'FAILED_PRECONDITION',
            StatusCode::ABORTED => 'ABORTED',
            StatusCode::OUT_OF_RANGE => 'OUT_OF_RANGE',
            StatusCode::UNIMPLEMENTED => 'UNIMPLEMENTED',
            StatusCode::INTERNAL => 'INTERNAL',
            StatusCode::UNAVAILABLE => 'UNAVAILABLE',
            StatusCode::DATA_LOSS => 'DATA_LOSS',
            StatusCode::UNAUTHENTICATED => 'UNAUTHENTICATED',
            default => (string) $code,
        };
    }
}
