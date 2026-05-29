<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\MetricsSnapshot;
use App\Service\MetricsService;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\InMemory;
use Psr\Log\NullLogger;

class MetricsServiceTest extends TestCase
{
    public function testCollectRendersBusinessGaugesInPrometheusFormat(): void
    {
        $service = new MetricsService(
            static fn(): MetricsSnapshot => new MetricsSnapshot(10, 5, 3),
            new CollectorRegistry(new InMemory(), false),
            new NullLogger()
        );

        $output = $service->collect();

        $this->assertStringContainsString('app_subscriptions_total 10', $output);
        $this->assertStringContainsString('app_repositories_total 5', $output);
        $this->assertStringContainsString('app_repositories_with_releases 3', $output);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $output);
        $this->assertStringContainsString('# TYPE app_subscriptions_total gauge', $output);
        $this->assertStringContainsString('# HELP app_subscriptions_total', $output);
    }

    public function testStillExportsRuntimeMetricsWhenSnapshotProviderFails(): void
    {
        $registry = new CollectorRegistry(new InMemory(), false);
        // A RED metric already accumulated in the registry (as if a request recorded it).
        $registry
            ->getOrRegisterCounter('', 'http_requests_total', 'reqs', ['method', 'route', 'status'])
            ->inc(['GET', '/health', '200']);

        $service = new MetricsService(
            static fn(): MetricsSnapshot => throw new \RuntimeException('database is down'),
            $registry,
            new NullLogger()
        );

        $output = $service->collect();

        // RED metrics + app_info still export; business gauges are skipped, not fatal.
        $this->assertStringContainsString('http_requests_total{', $output);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $output);
        $this->assertStringNotContainsString('app_subscriptions_total', $output);
    }
}
