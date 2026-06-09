<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * Injectable seam over the {@see GrpcStatus} enum, shared by the metric label
 * ({@see PrometheusGrpcMetrics}) and the per-call access log
 * ({@see MeasuredInvoker}) so both report the same code string.
 */
final readonly class GrpcStatusName
{
    public function of(int $code): string
    {
        return GrpcStatus::tryFrom($code)?->name ?? (string) $code;
    }
}
