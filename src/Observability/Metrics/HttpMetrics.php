<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * RED instrumentation for the HTTP boundary: every request contributes to the
 * request counter (Rate, and Errors via the status label) and the duration
 * histogram (Duration).
 */
interface HttpMetrics
{
    public function observe(string $method, string $route, int $status, float $durationSeconds): void;
}
