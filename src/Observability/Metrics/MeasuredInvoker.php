<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use App\Observability\CorrelationContext;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\InvokerInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * Decorates the RoadRunner gRPC {@see InvokerInterface} — the single dispatch
 * point every RPC passes through. Records RED metrics per call (deriving the
 * status code from a thrown {@see GRPCExceptionInterface}) and establishes a
 * correlation id, without touching the service implementation or needing a
 * per-method wrapper.
 *
 * @psalm-api
 */
final readonly class MeasuredInvoker implements InvokerInterface
{
    public function __construct(
        private InvokerInterface $inner,
        private GrpcMetrics $metrics,
        private CorrelationContext $correlation
    ) {
    }

    #[\Override]
    public function invoke(ServiceInterface $service, Method $method, ContextInterface $ctx, ?string $input): string
    {
        $this->correlation->start(bin2hex(random_bytes(16)));
        $start = microtime(true);
        $code = StatusCode::OK;

        try {
            return $this->inner->invoke($service, $method, $ctx, $input);
        } catch (GRPCExceptionInterface $e) {
            $code = $e->getCode();
            throw $e;
        } catch (\Throwable $e) {
            $code = StatusCode::INTERNAL;
            throw $e;
        } finally {
            $this->metrics->observe($method->name, $code, microtime(true) - $start);
            $this->correlation->reset();
        }
    }
}
