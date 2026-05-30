<?php

declare(strict_types=1);

namespace Tests\Observability\Event;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ScanCycleCompleted;
use App\Observability\Event\ScanMetricsListener;
use App\Observability\Metrics\ScanMetrics;
use PHPUnit\Framework\TestCase;

class ScanMetricsListenerTest extends TestCase
{
    public function testOnCycleCompletedRecordsCycleCountAndDuration(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('cycleCompleted')->with(7, 1.5);

        (new ScanMetricsListener($metrics))->onCycleCompleted(new ScanCycleCompleted(7, 1.5));
    }

    public function testOnRepositoryFailedRecordsErrorType(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('errorOccurred')->with('error');

        (new ScanMetricsListener($metrics))->onRepositoryFailed();
    }

    public function testOnRateLimitedRecordsRateLimitType(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('errorOccurred')->with('rate_limit');

        (new ScanMetricsListener($metrics))->onRateLimited();
    }

    public function testOnCycleFailedRecordsCycleType(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('errorOccurred')->with('cycle');

        (new ScanMetricsListener($metrics))->onCycleFailed();
    }

    public function testOnReleaseDetectedRecordsRelease(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('releaseDetected');

        (new ScanMetricsListener($metrics))->onReleaseDetected();
    }

    public function testOnNotificationBatchRecordsSentWhenAllDelivered(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('notification')->with('sent');

        (new ScanMetricsListener($metrics))->onNotificationBatch(
            new NotificationBatchCompleted('golang/go', 'v1.22', true)
        );
    }

    public function testOnNotificationBatchRecordsFailedWhenSomeDeliveriesFail(): void
    {
        $metrics = $this->createMock(ScanMetrics::class);
        $metrics->expects($this->once())->method('notification')->with('failed');

        (new ScanMetricsListener($metrics))->onNotificationBatch(
            new NotificationBatchCompleted('golang/go', 'v1.22', false)
        );
    }
}
