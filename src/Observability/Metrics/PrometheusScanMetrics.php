<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusScanMetrics implements ScanMetrics
{
    /** Scan cycles span many repos, so use coarser buckets than the request histograms. */
    private const CYCLE_BUCKETS = [0.5, 1, 2.5, 5, 10, 30, 60, 120, 300];

    public function __construct(private RegistryInterface $registry)
    {
    }

    #[\Override]
    public function cycleCompleted(int $repositoryCount, float $durationSeconds): void
    {
        $cycles = $this->registry->getOrRegisterCounter(
            '',
            'scan_cycles_total',
            'Total number of scan cycles run'
        );
        $cycles->inc();

        $repositories = $this->registry->getOrRegisterCounter(
            '',
            'scan_repositories_total',
            'Total repositories processed across scan cycles'
        );
        $repositories->incBy($repositoryCount);

        $duration = $this->registry->getOrRegisterHistogram(
            '',
            'scan_cycle_duration_seconds',
            'Scan cycle duration in seconds',
            [],
            self::CYCLE_BUCKETS
        );
        $duration->observe($durationSeconds);
    }

    #[\Override]
    public function errorOccurred(string $type): void
    {
        $errors = $this->registry->getOrRegisterCounter(
            '',
            'scan_errors_total',
            'Total number of errors during scan cycles',
            ['type']
        );
        $errors->inc([$type]);
    }

    #[\Override]
    public function releaseDetected(): void
    {
        $releases = $this->registry->getOrRegisterCounter(
            '',
            'releases_detected_total',
            'Total number of new releases detected'
        );
        $releases->inc();
    }

    #[\Override]
    public function notification(string $result): void
    {
        $notifications = $this->registry->getOrRegisterCounter(
            '',
            'notifications_total',
            'Total release-notification dispatch outcomes by repository',
            ['result']
        );
        $notifications->inc([$result]);
    }
}
