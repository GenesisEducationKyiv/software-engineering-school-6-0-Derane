<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\Saga\Enrollment\Application\SagaMetricsReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;

/** @psalm-api */
final readonly class MetricsService implements MetricsServiceInterface
{
    public function __construct(
        private SubscriptionCountPort $subscriptions,
        private RepositoryCountPort $repositories,
        private EnrollmentSagaCountPort $sagaCounts,
        private SagaMetricsReader $sagaMetrics,
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
            // HW9 D5 (FR12/AC7): the monolith saga funnel. Event-shaped counters off
            // the 006 saga_metrics table; state-shaped totals COUNT the saga rows.
            new Gauge(
                'welcome_command_published_total',
                'Welcome-email commands published by the saga relay',
                $this->sagaMetrics->welcomeCommandPublishedCount()
            ),
            new Gauge(
                'welcome_reply_consumed_total',
                'Well-formed WelcomeEmailOutcome replies consumed (including no-ops)',
                $this->sagaMetrics->welcomeReplyConsumedCount()
            ),
            new Gauge(
                'welcome_reply_noop_total',
                'WelcomeEmailOutcome replies that were idempotent no-ops',
                $this->sagaMetrics->welcomeReplyNoopCount()
            ),
            new Gauge(
                'timeout_swept_total',
                'Sagas compensated by the timeout sweeper',
                $this->sagaMetrics->timeoutSweptCount()
            ),
            new Gauge(
                'confirmed_total',
                'Sagas that reached the completed (confirmed) terminal state',
                $this->sagaCounts->confirmedTotal()
            ),
            new Gauge(
                'cancelled_total',
                'Sagas that reached the compensated (cancelled) terminal state',
                $this->sagaCounts->cancelledTotal()
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
