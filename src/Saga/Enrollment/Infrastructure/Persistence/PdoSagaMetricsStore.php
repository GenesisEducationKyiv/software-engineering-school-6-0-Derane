<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Application\SagaMetricsReader;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Infrastructure\Metrics\SagaMetric;
use PDO;

/**
 * The monolith saga-metrics counter store over the generic 006 saga_metrics table
 * (mirrors the notification side's PdoNotificationMetricsStore). Each increment is
 * an atomic UPSERT; each read returns 0 for an unseen metric. Implements both the
 * write (SagaMetricsRecorder) and read (SagaMetricsReader) ports on the shared
 * PDO::class.
 *
 * @psalm-api
 */
final readonly class PdoSagaMetricsStore implements SagaMetricsRecorder, SagaMetricsReader
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function recordWelcomeCommandPublished(): void
    {
        $this->increment(SagaMetric::WelcomeCommandPublished);
    }

    #[\Override]
    public function recordWelcomeReplyConsumed(): void
    {
        $this->increment(SagaMetric::WelcomeReplyConsumed);
    }

    #[\Override]
    public function recordWelcomeReplyNoop(): void
    {
        $this->increment(SagaMetric::WelcomeReplyNoop);
    }

    #[\Override]
    public function recordTimeoutSwept(): void
    {
        $this->increment(SagaMetric::TimeoutSwept);
    }

    #[\Override]
    public function welcomeCommandPublishedCount(): int
    {
        return $this->count(SagaMetric::WelcomeCommandPublished);
    }

    #[\Override]
    public function welcomeReplyConsumedCount(): int
    {
        return $this->count(SagaMetric::WelcomeReplyConsumed);
    }

    #[\Override]
    public function welcomeReplyNoopCount(): int
    {
        return $this->count(SagaMetric::WelcomeReplyNoop);
    }

    #[\Override]
    public function timeoutSweptCount(): int
    {
        return $this->count(SagaMetric::TimeoutSwept);
    }

    private function increment(SagaMetric $metric): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO saga_metrics (metric_name, metric_value)
             VALUES (:metric, 1)
             ON CONFLICT (metric_name)
             DO UPDATE SET metric_value = saga_metrics.metric_value + 1'
        );
        $stmt->execute([':metric' => $metric->value]);
    }

    private function count(SagaMetric $metric): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT metric_value FROM saga_metrics WHERE metric_name = :metric'
        );
        $stmt->execute([':metric' => $metric->value]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
