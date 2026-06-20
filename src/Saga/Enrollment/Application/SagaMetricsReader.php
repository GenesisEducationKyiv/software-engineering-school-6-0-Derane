<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application;

/**
 * Reads the event-shaped monolith saga counters off the 006 saga_metrics table so
 * MetricsService can expose them as Gauges (FR12/AC7). The complement of
 * {@see SagaMetricsRecorder} (the write side).
 *
 * @psalm-api
 */
interface SagaMetricsReader
{
    public function welcomeCommandPublishedCount(): int;

    public function welcomeReplyConsumedCount(): int;

    public function welcomeReplyNoopCount(): int;

    public function timeoutSweptCount(): int;
}
