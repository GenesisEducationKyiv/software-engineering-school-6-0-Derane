<?php

declare(strict_types=1);

namespace App\Observability\Event;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ScanCycleCompleted;
use App\Observability\Metrics\ScanMetrics;

/**
 * Translates scanner application events into RED/throughput metrics via the existing
 * {@see ScanMetrics} contract. The Prometheus error-type taxonomy
 * (rate_limit|error|cycle) lives here — it is an observability concern, not
 * something the orchestrator should know.
 *
 * @psalm-api
 */
final readonly class ScanMetricsListener
{
    public function __construct(private ScanMetrics $metrics)
    {
    }

    public function onCycleCompleted(ScanCycleCompleted $event): void
    {
        $this->metrics->cycleCompleted($event->repositoryCount, $event->durationSeconds);
    }

    public function onRepositoryFailed(): void
    {
        $this->metrics->errorOccurred('error');
    }

    public function onRateLimited(): void
    {
        $this->metrics->errorOccurred('rate_limit');
    }

    public function onCycleFailed(): void
    {
        $this->metrics->errorOccurred('cycle');
    }

    public function onReleaseDetected(): void
    {
        $this->metrics->releaseDetected();
    }

    public function onNotificationBatch(NotificationBatchCompleted $event): void
    {
        $this->metrics->notification($event->allDelivered ? 'sent' : 'failed');
    }
}
