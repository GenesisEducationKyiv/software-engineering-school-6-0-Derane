<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use App\Observability\CorrelationContextInterface;
use App\Observability\CorrelationIdGeneratorInterface;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCExceptionInterface;
use Spiral\RoadRunner\GRPC\InvokerInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

/**
 * Decorates the RoadRunner gRPC {@see InvokerInterface} — the single dispatch
 * point every RPC passes through. Per call it: establishes a correlation id,
 * records RED metrics (deriving the status code from a thrown
 * {@see GRPCExceptionInterface}), and emits a structured access log — the gRPC
 * counterpart of the HTTP {@see \App\Middleware\RequestMetricsMiddleware}.
 * INTERNAL failures additionally get an error log with the exception, mirroring
 * the HTTP 500 path in {@see \App\Middleware\ErrorHandlerMiddleware}; this is
 * the single place server-side gRPC failures are logged. No service code or
 * per-method wrapper is touched.
 *
 * Honours an inbound `x-request-id` metadata entry (mirroring the HTTP
 * `X-Request-Id` header) so a trace can span an upstream caller and this gRPC
 * call; falls back to a generated id otherwise.
 *
 * @psalm-api
 */
final readonly class MeasuredInvoker implements InvokerInterface
{
    private const REQUEST_ID_METADATA = 'x-request-id';

    public function __construct(
        private InvokerInterface $inner,
        private GrpcMetrics $metrics,
        private GrpcStatusName $statusName,
        private CorrelationContextInterface $correlation,
        private CorrelationIdGeneratorInterface $idGenerator,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function invoke(ServiceInterface $service, Method $method, ContextInterface $ctx, ?string $input): string
    {
        $this->correlation->start($this->resolveCorrelationId($ctx));
        $start = microtime(true);
        $code = StatusCode::OK;
        $failure = null;

        try {
            return $this->inner->invoke($service, $method, $ctx, $input);
        } catch (GRPCExceptionInterface $e) {
            $code = $e->getCode();
            $failure = $e;
            throw $e;
        } catch (\Throwable $e) {
            $code = StatusCode::INTERNAL;
            $failure = $e;
            throw $e;
        } finally {
            $durationSeconds = microtime(true) - $start;
            $this->metrics->observe($method->name, $code, $durationSeconds);
            $this->logCall($method->name, $code, $durationSeconds, $failure);
            $this->correlation->reset();
        }
    }

    private function resolveCorrelationId(ContextInterface $ctx): string
    {
        /** @var list<string>|null $values — gRPC metadata entries decode to string lists */
        $values = $ctx->getValue(self::REQUEST_ID_METADATA);
        if (is_array($values) && isset($values[0]) && $values[0] !== '') {
            return $values[0];
        }

        return $this->idGenerator->generate();
    }

    private function logCall(string $method, int $code, float $durationSeconds, ?\Throwable $failure): void
    {
        $this->logger->info('grpc call handled', [
            'grpc_method' => $method,
            'grpc_code' => $this->statusName->of($code),
            'duration_ms' => round($durationSeconds * 1000.0, 2),
        ]);

        if ($code === StatusCode::INTERNAL && $failure !== null) {
            $this->logger->error('Unhandled gRPC exception: ' . $failure->getMessage(), [
                'grpc_method' => $method,
                'exception' => $failure::class,
                'trace' => $failure->getTraceAsString(),
            ]);
        }
    }
}
