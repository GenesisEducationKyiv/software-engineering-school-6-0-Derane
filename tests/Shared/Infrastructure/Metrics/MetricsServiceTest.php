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
}
