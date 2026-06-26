<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Metrics;

use App\RepositoryTracking\Repositories\Domain\RepositoryCountPort;
use App\Shared\Infrastructure\Metrics\MetricsService;
use App\Shared\Infrastructure\Metrics\PrometheusFormatter;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use PHPUnit\Framework\TestCase;

class MetricsServiceTest extends TestCase
{
    public function testCollectReturnsPrometheusFormat(): void
    {
        $subscriptions = $this->createMock(SubscriptionCountPort::class);
        $subscriptions->method('countAll')->willReturn(10);

        $repositories = $this->createMock(RepositoryCountPort::class);
        $repositories->method('countAll')->willReturn(5);
        $repositories->method('countWithReleases')->willReturn(3);

        $service = new MetricsService($subscriptions, $repositories, new PrometheusFormatter());
        $output = $service->collect();

        $this->assertStringContainsString('app_subscriptions_total 10', $output);
        $this->assertStringContainsString('app_repositories_total 5', $output);
        $this->assertStringContainsString('app_repositories_with_releases 3', $output);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $output);
        $this->assertStringContainsString('# TYPE app_subscriptions_total gauge', $output);
        $this->assertStringContainsString('# HELP app_subscriptions_total', $output);
    }

    public function testCollectOutputIsByteIdenticalToTheFrozenPrometheusContract(): void
    {
        $subscriptions = $this->createMock(SubscriptionCountPort::class);
        $subscriptions->method('countAll')->willReturn(7);

        $repositories = $this->createMock(RepositoryCountPort::class);
        $repositories->method('countAll')->willReturn(4);
        $repositories->method('countWithReleases')->willReturn(2);

        $service = new MetricsService($subscriptions, $repositories, new PrometheusFormatter());

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
            '# HELP app_info Application info',
            '# TYPE app_info gauge',
            'app_info{version="1.0.0"} 1',
            '',
        ]);

        $this->assertSame($expected, $service->collect());
    }
}
