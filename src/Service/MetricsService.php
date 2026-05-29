<?php

declare(strict_types=1);

namespace App\Service;

use App\Metrics\Gauge;
use App\Metrics\PrometheusFormatter;
use App\Repository\MetricsRepositoryInterface;

/** @psalm-api */
final readonly class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        private MetricsRepositoryInterface $metricsRepository,
        private PrometheusFormatter $formatter
    ) {
    }

    #[\Override]
    public function collect(): string
    {
        $snapshot = $this->metricsRepository->snapshot();

        return $this->formatter->format([
            new Gauge(
                'app_subscriptions_total',
                'Total number of active subscriptions',
                $snapshot->subscriptions
            ),
            new Gauge(
                'app_repositories_total',
                'Total number of tracked repositories',
                $snapshot->repositories
            ),
            new Gauge(
                'app_repositories_with_releases',
                'Repositories that have at least one known release',
                $snapshot->repositoriesWithReleases
            ),
            new Gauge(
                'app_info',
                'Application info',
                1,
                ['version' => '1.0.0']
            ),
        ]);
    }
}
