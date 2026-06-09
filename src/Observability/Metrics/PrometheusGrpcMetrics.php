<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\Counter;
use Prometheus\Histogram;
use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusGrpcMetrics implements GrpcMetrics
{
    private Counter $handled;
    private Histogram $duration;

    public function __construct(
        RegistryInterface $registry,
        private GrpcStatusName $statusName
    ) {
        $this->handled = $registry->getOrRegisterCounter(
            '',
            'grpc_server_handled_total',
            'Total number of gRPC calls handled',
            ['grpc_method', 'grpc_code']
        );
        $this->duration = $registry->getOrRegisterHistogram(
            '',
            'grpc_server_handling_seconds',
            'gRPC call handling duration in seconds',
            ['grpc_method']
        );
    }

    #[\Override]
    public function observe(string $method, int $code, float $durationSeconds): void
    {
        $this->handled->inc([$method, $this->statusName->of($code)]);
        $this->duration->observe($durationSeconds, [$method]);
    }
}
