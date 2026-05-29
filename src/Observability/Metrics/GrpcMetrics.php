<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * RED instrumentation for the gRPC boundary: handled-call counter (Rate, and
 * Errors via the status-code label) and a handling-duration histogram.
 */
interface GrpcMetrics
{
    public function observe(string $method, int $code, float $durationSeconds): void;
}
