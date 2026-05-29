<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusHttpMetrics implements HttpMetrics
{
    public function __construct(private RegistryInterface $registry)
    {
    }

    #[\Override]
    public function observe(string $method, string $route, int $status, float $durationSeconds): void
    {
        $requests = $this->registry->getOrRegisterCounter(
            '',
            'http_requests_total',
            'Total number of HTTP requests',
            ['method', 'route', 'status']
        );
        $requests->inc([$method, $route, (string) $status]);

        $duration = $this->registry->getOrRegisterHistogram(
            '',
            'http_request_duration_seconds',
            'HTTP request duration in seconds',
            ['method', 'route']
        );
        $duration->observe($durationSeconds, [$method, $route]);
    }
}
