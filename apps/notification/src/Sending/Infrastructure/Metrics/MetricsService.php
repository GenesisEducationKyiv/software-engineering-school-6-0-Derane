<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Metrics;

use App\Sending\Application\NotificationMetricsReader;

final readonly class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        private NotificationMetricsReader $reader,
        private PrometheusFormatter $formatter,
    ) {
    }

    #[\Override]
    public function collect(): string
    {
        return $this->formatter->format([
            new Metric(
                'notification_consumed_total',
                'Messages consumed by the notification service',
                MetricType::Counter,
                $this->reader->consumedCount()
            ),
            new Metric(
                'notification_delivered_total',
                'Emails successfully delivered',
                MetricType::Counter,
                $this->reader->deliveredCount()
            ),
            new Metric(
                'notification_deduped_total',
                'Messages skipped by idempotency checks',
                MetricType::Counter,
                $this->reader->dedupedCount()
            ),
            new Metric(
                'notification_failed_total',
                'Transient processing failures',
                MetricType::Counter,
                $this->reader->failedCount()
            ),
            new Metric(
                'notification_contention_total',
                'Messages parked while another worker held the ledger claim',
                MetricType::Counter,
                $this->reader->contentionCount()
            ),
            new Metric(
                'notification_dlq_total',
                'Messages routed to the dead-letter queue',
                MetricType::Counter,
                $this->reader->dlqCount()
            ),
            new Metric(
                'notification_superseded_total',
                'Emails sent whose ledger claim was taken over mid-send (superseded duplicates)',
                MetricType::Counter,
                $this->reader->supersededCount()
            ),
        ]);
    }
}
