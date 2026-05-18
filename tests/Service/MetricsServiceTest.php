<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Domain\MetricsSnapshot;
use App\Metrics\PrometheusFormatter;
use App\Repository\MetricsRepositoryInterface;
use App\Service\MetricsService;
use PHPUnit\Framework\TestCase;

class MetricsServiceTest extends TestCase
{
    public function testCollectReturnsPrometheusFormat(): void
    {
        $repository = $this->createMock(MetricsRepositoryInterface::class);
        $repository->method('snapshot')->willReturn(new MetricsSnapshot(10, 5, 3));

        $service = new MetricsService($repository, new PrometheusFormatter());
        $output = $service->collect();

        $this->assertStringContainsString('app_subscriptions_total 10', $output);
        $this->assertStringContainsString('app_repositories_total 5', $output);
        $this->assertStringContainsString('app_repositories_with_releases 3', $output);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $output);
        $this->assertStringContainsString('# TYPE app_subscriptions_total gauge', $output);
        $this->assertStringContainsString('# HELP app_subscriptions_total', $output);
    }
}
