<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Persistence;

use App\Sending\Application\DeliveryOutcomeRecorder;
use App\Sending\Application\MessageProcessingStatsRecorder;
use App\Sending\Application\NotificationMetricsReader;

final readonly class PdoNotificationMetricsStore implements
    DeliveryOutcomeRecorder,
    MessageProcessingStatsRecorder,
    NotificationMetricsReader
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function recordConsumed(): void
    {
        $this->increment(NotificationMetric::Consumed);
    }

    #[\Override]
    public function recordFailed(): void
    {
        $this->increment(NotificationMetric::Failed);
    }

    #[\Override]
    public function recordContention(): void
    {
        $this->increment(NotificationMetric::Contention);
    }

    #[\Override]
    public function recordDlq(): void
    {
        $this->increment(NotificationMetric::Dlq);
    }

    #[\Override]
    public function recordDelivered(): void
    {
        $this->increment(NotificationMetric::Delivered);
    }

    #[\Override]
    public function recordDeduped(): void
    {
        $this->increment(NotificationMetric::Deduped);
    }

    #[\Override]
    public function recordSuperseded(): void
    {
        $this->increment(NotificationMetric::Superseded);
    }

    #[\Override]
    public function consumedCount(): int
    {
        return $this->count(NotificationMetric::Consumed);
    }

    #[\Override]
    public function deliveredCount(): int
    {
        return $this->count(NotificationMetric::Delivered);
    }

    #[\Override]
    public function dedupedCount(): int
    {
        return $this->count(NotificationMetric::Deduped);
    }

    #[\Override]
    public function failedCount(): int
    {
        return $this->count(NotificationMetric::Failed);
    }

    #[\Override]
    public function contentionCount(): int
    {
        return $this->count(NotificationMetric::Contention);
    }

    #[\Override]
    public function dlqCount(): int
    {
        return $this->count(NotificationMetric::Dlq);
    }

    #[\Override]
    public function supersededCount(): int
    {
        return $this->count(NotificationMetric::Superseded);
    }

    private function increment(NotificationMetric $metric): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_metrics (metric_name, metric_value)
             VALUES (:metric, 1)
             ON CONFLICT (metric_name)
             DO UPDATE SET metric_value = notification_metrics.metric_value + 1'
        );
        $stmt->execute([':metric' => $metric->value]);
    }

    private function count(NotificationMetric $metric): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT metric_value FROM notification_metrics WHERE metric_name = :metric'
        );
        $stmt->execute([':metric' => $metric->value]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
