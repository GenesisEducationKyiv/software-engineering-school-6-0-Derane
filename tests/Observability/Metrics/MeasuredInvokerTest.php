<?php

declare(strict_types=1);

namespace Tests\Observability\Metrics;

use App\Observability\CorrelationContext;
use App\Observability\Metrics\MeasuredInvoker;
use App\Observability\Metrics\PrometheusGrpcMetrics;
use Grpc\ReleaseNotifier\V1\ReleaseNotifierServiceInterface;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Spiral\RoadRunner\GRPC\ContextInterface;
use Spiral\RoadRunner\GRPC\Exception\GRPCException;
use Spiral\RoadRunner\GRPC\InvokerInterface;
use Spiral\RoadRunner\GRPC\Method;
use Spiral\RoadRunner\GRPC\ServiceInterface;
use Spiral\RoadRunner\GRPC\StatusCode;

class MeasuredInvokerTest extends TestCase
{
    private CollectorRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
    }

    public function testRecordsOkAndBindsCorrelationIdDuringTheCall(): void
    {
        $correlation = new CorrelationContext();

        $inner = new class ($correlation) implements InvokerInterface {
            public ?string $idDuringCall = null;

            public function __construct(private CorrelationContext $correlation)
            {
            }

            #[\Override]
            public function invoke(
                ServiceInterface $service,
                Method $method,
                ContextInterface $ctx,
                ?string $input
            ): string {
                $this->idDuringCall = $this->correlation->id();
                return 'serialized-reply';
            }
        };

        $invoker = new MeasuredInvoker($inner, new PrometheusGrpcMetrics($this->registry), $correlation);

        $result = $invoker->invoke(
            $this->createMock(ServiceInterface::class),
            $this->method(),
            $this->createMock(ContextInterface::class),
            null
        );

        $this->assertSame('serialized-reply', $result);
        $this->assertNotNull($inner->idDuringCall, 'correlation id must be set while the call runs');
        $this->assertNull($correlation->id(), 'correlation id must be reset afterwards');

        $output = $this->render();
        $this->assertStringContainsString('grpc_method="Health"', $output);
        $this->assertStringContainsString('grpc_code="OK"', $output);
        $this->assertStringContainsString('grpc_server_handling_seconds_bucket', $output);
    }

    public function testMapsThrownStatusCodeAndRethrows(): void
    {
        $inner = new class implements InvokerInterface {
            #[\Override]
            public function invoke(
                ServiceInterface $service,
                Method $method,
                ContextInterface $ctx,
                ?string $input
            ): string {
                throw GRPCException::create('missing', StatusCode::NOT_FOUND);
            }
        };

        $invoker = new MeasuredInvoker($inner, new PrometheusGrpcMetrics($this->registry), new CorrelationContext());

        try {
            $invoker->invoke(
                $this->createMock(ServiceInterface::class),
                $this->method(),
                $this->createMock(ContextInterface::class),
                null
            );
            $this->fail('GRPCException should propagate');
        } catch (GRPCException) {
            // expected — re-thrown so RoadRunner still maps it to a gRPC status
        }

        $this->assertStringContainsString('grpc_code="NOT_FOUND"', $this->render());
    }

    private function method(): Method
    {
        return Method::parse(new \ReflectionMethod(ReleaseNotifierServiceInterface::class, 'Health'));
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
