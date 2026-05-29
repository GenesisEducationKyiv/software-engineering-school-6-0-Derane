<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MetricsRepositoryInterface;
use Prometheus\RegistryInterface;
use Prometheus\RenderTextFormat;

/** @psalm-api */
final readonly class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        private MetricsRepositoryInterface $metricsRepository,
        private RegistryInterface $registry
    ) {
    }

    #[\Override]
    public function collect(): string
    {
        $snapshot = $this->metricsRepository->snapshot();

        $this->registry->getOrRegisterGauge(
            '',
            'app_subscriptions_total',
            'Total number of active subscriptions'
        )->set($snapshot->subscriptions);

        $this->registry->getOrRegisterGauge(
            '',
            'app_repositories_total',
            'Total number of tracked repositories'
        )->set($snapshot->repositories);

        $this->registry->getOrRegisterGauge(
            '',
            'app_repositories_with_releases',
            'Repositories that have at least one known release'
        )->set($snapshot->repositoriesWithReleases);

        $this->registry->getOrRegisterGauge(
            '',
            'app_info',
            'Application info',
            ['version']
        )->set(1, ['1.0.0']);

        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
