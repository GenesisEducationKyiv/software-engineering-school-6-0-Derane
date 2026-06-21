<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Metrics;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\Saga\Enrollment\Application\SagaMetricsReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaCountPort;
use App\Shared\Infrastructure\Metrics\MetricsService;
use App\Shared\Infrastructure\Metrics\PrometheusFormatter;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use PHPUnit\Framework\TestCase;

class MetricsServiceTest extends TestCase
{
    public function testCollectReturnsPrometheusFormat(): void
    {
        $service = new MetricsService(
            $this->subscriptions(10),
            $this->repositories(5, 3),
            $this->sagaCounts(0, 0),
            $this->sagaMetrics(0, 0, 0, 0),
            new PrometheusFormatter(),
        );
        $output = $service->collect();

        $this->assertStringContainsString('app_subscriptions_total 10', $output);
        $this->assertStringContainsString('app_repositories_total 5', $output);
        $this->assertStringContainsString('app_repositories_with_releases 3', $output);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $output);
        $this->assertStringContainsString('# TYPE app_subscriptions_total gauge', $output);
        $this->assertStringContainsString('# HELP app_subscriptions_total', $output);
    }

    public function testCollectSurfacesTheFiveSagaCounters(): void
    {
        // FR12/AC7 happy path: published / reply-consumed / confirmed each read 1.
        $service = new MetricsService(
            $this->subscriptions(0),
            $this->repositories(0, 0),
            $this->sagaCounts(confirmed: 1, cancelled: 0),
            $this->sagaMetrics(published: 1, consumed: 1, noop: 0, swept: 0),
            new PrometheusFormatter(),
        );
        $output = $service->collect();

        $this->assertStringContainsString('welcome_command_published_total 1', $output);
        $this->assertStringContainsString('welcome_reply_consumed_total 1', $output);
        $this->assertStringContainsString('confirmed_total 1', $output);
        $this->assertStringContainsString('cancelled_total 0', $output);
        $this->assertStringContainsString('timeout_swept_total 0', $output);
        $this->assertStringContainsString('welcome_reply_noop_total 0', $output);
    }

    public function testCollectSurfacesTheFailurePathCounters(): void
    {
        // FR12/AC7 failure path: cancelled and (on a swept timeout) timeout_swept increment.
        $service = new MetricsService(
            $this->subscriptions(0),
            $this->repositories(0, 0),
            $this->sagaCounts(confirmed: 0, cancelled: 1),
            $this->sagaMetrics(published: 1, consumed: 0, noop: 0, swept: 1),
            new PrometheusFormatter(),
        );
        $output = $service->collect();

        $this->assertStringContainsString('cancelled_total 1', $output);
        $this->assertStringContainsString('timeout_swept_total 1', $output);
    }

    public function testCollectOutputIsByteIdenticalToTheFrozenPrometheusContract(): void
    {
        $service = new MetricsService(
            $this->subscriptions(7),
            $this->repositories(4, 2),
            $this->sagaCounts(confirmed: 9, cancelled: 8),
            $this->sagaMetrics(published: 11, consumed: 10, noop: 1, swept: 2),
            new PrometheusFormatter(),
        );

        $expected = implode("\n", [
            '# HELP app_subscriptions_total Total number of active subscriptions',
            '# TYPE app_subscriptions_total gauge',
            'app_subscriptions_total 7',
            '# HELP app_repositories_total Total number of tracked repositories',
            '# TYPE app_repositories_total gauge',
            'app_repositories_total 4',
            '# HELP app_repositories_with_releases Repositories that have at least one known release',
            '# TYPE app_repositories_with_releases gauge',
            'app_repositories_with_releases 2',
            '# HELP welcome_command_published_total Welcome-email commands published by the saga relay',
            '# TYPE welcome_command_published_total gauge',
            'welcome_command_published_total 11',
            '# HELP welcome_reply_consumed_total Well-formed WelcomeEmailOutcome replies consumed (including no-ops)',
            '# TYPE welcome_reply_consumed_total gauge',
            'welcome_reply_consumed_total 10',
            '# HELP welcome_reply_noop_total WelcomeEmailOutcome replies that were idempotent no-ops',
            '# TYPE welcome_reply_noop_total gauge',
            'welcome_reply_noop_total 1',
            '# HELP timeout_swept_total Sagas compensated by the timeout sweeper',
            '# TYPE timeout_swept_total gauge',
            'timeout_swept_total 2',
            '# HELP confirmed_total Sagas that reached the completed (confirmed) terminal state',
            '# TYPE confirmed_total gauge',
            'confirmed_total 9',
            '# HELP cancelled_total Sagas that reached the compensated (cancelled) terminal state',
            '# TYPE cancelled_total gauge',
            'cancelled_total 8',
            '# HELP app_info Application info',
            '# TYPE app_info gauge',
            'app_info{version="1.0.0"} 1',
            '',
        ]);

        $this->assertSame($expected, $service->collect());
    }

    private function subscriptions(int $countAll): SubscriptionCountPort
    {
        $mock = $this->createMock(SubscriptionCountPort::class);
        $mock->method('countAll')->willReturn($countAll);

        return $mock;
    }

    private function repositories(int $countAll, int $withReleases): RepositoryCountPort
    {
        $mock = $this->createMock(RepositoryCountPort::class);
        $mock->method('countAll')->willReturn($countAll);
        $mock->method('countWithReleases')->willReturn($withReleases);

        return $mock;
    }

    private function sagaCounts(int $confirmed, int $cancelled): EnrollmentSagaCountPort
    {
        $mock = $this->createMock(EnrollmentSagaCountPort::class);
        $mock->method('confirmedTotal')->willReturn($confirmed);
        $mock->method('cancelledTotal')->willReturn($cancelled);

        return $mock;
    }

    private function sagaMetrics(int $published, int $consumed, int $noop, int $swept): SagaMetricsReader
    {
        $mock = $this->createMock(SagaMetricsReader::class);
        $mock->method('welcomeCommandPublishedCount')->willReturn($published);
        $mock->method('welcomeReplyConsumedCount')->willReturn($consumed);
        $mock->method('welcomeReplyNoopCount')->willReturn($noop);
        $mock->method('timeoutSweptCount')->willReturn($swept);

        return $mock;
    }
}
