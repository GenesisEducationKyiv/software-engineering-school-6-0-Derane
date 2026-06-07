<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;

/** @psalm-api */
final readonly class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        private SubscriptionCountPort $subscriptions,
        private RepositoryCountPort $repositories,
        private PrometheusFormatter $formatter
    ) {
    }

    #[\Override]
    public function collect(): string
    {
        return $this->formatter->format([
            new Gauge(
                'app_subscriptions_total',
                'Total number of active subscriptions',
                $this->subscriptions->countAll()
            ),
            new Gauge(
                'app_repositories_total',
                'Total number of tracked repositories',
                $this->repositories->countAll()
            ),
            new Gauge(
                'app_repositories_with_releases',
                'Repositories that have at least one known release',
                $this->repositories->countWithReleases()
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
