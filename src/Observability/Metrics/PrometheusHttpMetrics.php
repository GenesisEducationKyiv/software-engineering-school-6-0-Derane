<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusHttpMetrics implements HttpMetrics
{
    private Counter $requests;
    private Histogram $duration;

    public function __construct(RegistryInterface $registry)
    {
        $this->requests = $registry->getOrRegisterCounter(
            '',
            'http_requests_total',
            'Total number of HTTP requests',
            ['method', 'route', 'status']
        );
        $this->duration = $registry->getOrRegisterHistogram(
            '',
            'http_request_duration_seconds',
            'HTTP request duration in seconds',
            ['method', 'route', 'status']
        );
    }

    #[\Override]
    public function observe(string $method, string $route, int $status, float $durationSeconds): void
    {
        $this->requests->inc([$method, $route, (string) $status]);
        $this->duration->observe($durationSeconds, [$method, $route, (string) $status]);
    }
}
