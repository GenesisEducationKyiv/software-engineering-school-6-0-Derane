<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\RegistryInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * @psalm-api
 */
final readonly class PrometheusGrpcMetrics implements GrpcMetrics
{
    public function __construct(private RegistryInterface $registry)
    {
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
        $handled->inc([$method, $this->codeName($code)]);

        $duration = $this->registry->getOrRegisterHistogram(
            '',
            'grpc_server_handling_seconds',
            'gRPC call handling duration in seconds',
            ['grpc_method']
        );
        $duration->observe($durationSeconds, [$method]);
    }

    private function codeName(int $code): string
    {
        return match ($code) {
            StatusCode::OK => 'OK',
            StatusCode::CANCELLED => 'CANCELLED',
            StatusCode::UNKNOWN => 'UNKNOWN',
            StatusCode::INVALID_ARGUMENT => 'INVALID_ARGUMENT',
            StatusCode::DEADLINE_EXCEEDED => 'DEADLINE_EXCEEDED',
            StatusCode::NOT_FOUND => 'NOT_FOUND',
            StatusCode::ALREADY_EXISTS => 'ALREADY_EXISTS',
            StatusCode::PERMISSION_DENIED => 'PERMISSION_DENIED',
            StatusCode::RESOURCE_EXHAUSTED => 'RESOURCE_EXHAUSTED',
            StatusCode::FAILED_PRECONDITION => 'FAILED_PRECONDITION',
            StatusCode::ABORTED => 'ABORTED',
            StatusCode::OUT_OF_RANGE => 'OUT_OF_RANGE',
            StatusCode::UNIMPLEMENTED => 'UNIMPLEMENTED',
            StatusCode::INTERNAL => 'INTERNAL',
            StatusCode::UNAVAILABLE => 'UNAVAILABLE',
            StatusCode::DATA_LOSS => 'DATA_LOSS',
            StatusCode::UNAUTHENTICATED => 'UNAUTHENTICATED',
            default => (string) $code,
        };
    }
}
