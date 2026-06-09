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
        $this->increment('consumed_total');
    }

    #[\Override]
    public function recordFailed(): void
    {
        $this->increment('failed_total');
    }

    #[\Override]
    public function recordContention(): void
    {
        $this->increment('contention_total');
    }

    #[\Override]
    public function recordDlq(): void
    {
        $this->increment('dlq_total');
    }

    #[\Override]
    public function recordDelivered(): void
    {
        $this->increment('delivered_total');
    }

    #[\Override]
    public function recordDeduped(): void
    {
        $this->increment('deduped_total');
    }

    #[\Override]
    public function consumedCount(): int
    {
        return $this->count('consumed_total');
    }

    #[\Override]
    public function deliveredCount(): int
    {
        return $this->count('delivered_total');
    }

    #[\Override]
    public function dedupedCount(): int
    {
        return $this->count('deduped_total');
    }

    #[\Override]
    public function failedCount(): int
    {
        return $this->count('failed_total');
    }

    #[\Override]
    public function contentionCount(): int
    {
        return $this->count('contention_total');
    }

    #[\Override]
    public function dlqCount(): int
    {
        return $this->count('dlq_total');
    }

    private function increment(string $metric): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notification_metrics (metric_name, metric_value)
             VALUES (:metric, 1)
             ON CONFLICT (metric_name)
             DO UPDATE SET metric_value = notification_metrics.metric_value + 1'
        );
        $stmt->execute([':metric' => $metric]);
    }

    private function count(string $metric): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT metric_value FROM notification_metrics WHERE metric_name = :metric'
        );
        $stmt->execute([':metric' => $metric]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
