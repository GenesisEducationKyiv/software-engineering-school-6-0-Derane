<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusScanMetrics implements ScanMetrics
{
    /** Scan cycles span many repos, so use coarser buckets than the request histograms. */
    private const CYCLE_BUCKETS = [0.5, 1, 2.5, 5, 10, 30, 60, 120, 300];

    private Counter $cycles;
    private Counter $repositories;
    private Histogram $cycleDuration;
    private Counter $errors;
    private Counter $releases;
    private Counter $notifications;

    public function __construct(RegistryInterface $registry)
    {
        $this->cycles = $registry->getOrRegisterCounter(
            '',
            'scan_cycles_total',
            'Total number of scan cycles run'
        );
        $this->repositories = $registry->getOrRegisterCounter(
            '',
            'scan_repositories_total',
            'Total repositories processed across scan cycles'
        );
        $this->cycleDuration = $registry->getOrRegisterHistogram(
            '',
            'scan_cycle_duration_seconds',
            'Scan cycle duration in seconds',
            [],
            self::CYCLE_BUCKETS
        );
        $this->errors = $registry->getOrRegisterCounter(
            '',
            'scan_errors_total',
            'Total number of errors during scan cycles',
            ['type']
        );
        $this->releases = $registry->getOrRegisterCounter(
            '',
            'releases_detected_total',
            'Total number of new releases detected'
        );
        $this->notifications = $registry->getOrRegisterCounter(
            '',
            'notifications_total',
            'Total release-notification dispatch outcomes by result',
            ['result']
        );
    }

    #[\Override]
    public function cycleCompleted(int $repositoryCount, float $durationSeconds): void
    {
        $this->cycles->inc();
        $this->repositories->incBy($repositoryCount);
        $this->cycleDuration->observe($durationSeconds);
    }

    #[\Override]
    public function errorOccurred(string $type): void
    {
        $this->errors->inc([$type]);
    }

    #[\Override]
    public function releaseDetected(): void
    {
        $this->releases->inc();
    }

    #[\Override]
    public function notification(string $result): void
    {
        $this->notifications->inc([$result]);
    }
}
