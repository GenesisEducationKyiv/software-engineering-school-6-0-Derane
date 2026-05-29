<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

/**
 * RED instrumentation for the background scanner. A scan cycle is the unit of
 * work: `cycleCompleted` feeds the cycle counter (Rate) and duration histogram
 * (Duration); `errorOccurred` feeds the error counter (Errors). The remaining
 * methods track domain throughput (releases detected, notification outcomes).
 */
interface ScanMetrics
{
    public function cycleCompleted(int $repositoryCount, float $durationSeconds): void;

    public function errorOccurred(string $type): void;

    public function releaseDetected(): void;

    public function notification(string $result): void;
}
