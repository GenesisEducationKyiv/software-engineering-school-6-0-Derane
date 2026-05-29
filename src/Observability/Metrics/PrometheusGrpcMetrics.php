<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\RegistryInterface;

/**
 * @psalm-api
 */
final readonly class PrometheusGrpcMetrics implements GrpcMetrics
{
    public function __construct(
        private RegistryInterface $registry,
        private GrpcStatusName $statusName
    ) {
    }

    #[\Override]
    public function observe(string $method, int $code, float $durationSeconds): void
    {
        $handled = $this->registry->getOrRegisterCounter(
            '',
            'grpc_server_handled_total',
            'Total number of gRPC calls handled',
            ['grpc_method', 'grpc_code']
        );
        $handled->inc([$method, $this->statusName->of($code)]);

        $duration = $this->registry->getOrRegisterHistogram(
            '',
            'grpc_server_handling_seconds',
            'gRPC call handling duration in seconds',
            ['grpc_method']
        );
        $duration->observe($durationSeconds, [$method]);
    }
}
