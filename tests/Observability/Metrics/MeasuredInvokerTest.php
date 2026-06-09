<?php

declare(strict_types=1);

namespace Tests\Observability\Metrics;

use App\Observability\CorrelationContext;
use App\Observability\Metrics\GrpcStatusName;
use App\Observability\Metrics\MeasuredInvoker;
use App\Observability\Metrics\PrometheusGrpcMetrics;
use App\Observability\RandomCorrelationIdGenerator;
use Grpc\ReleaseNotifier\V1\ReleaseNotifierServiceInterface;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
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
    private TestHandler $logs;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->logs = new TestHandler();
        $this->logger = new Logger('test', [$this->logs]);
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

        $result = $this->invoke($this->makeInvoker($inner, $correlation));

        $this->assertSame('serialized-reply', $result);
        $this->assertNotNull($inner->idDuringCall, 'correlation id must be set while the call runs');
        $this->assertNull($correlation->id(), 'correlation id must be reset afterwards');

        $output = $this->render();
        $this->assertStringContainsString('grpc_method="Health"', $output);
        $this->assertStringContainsString('grpc_code="OK"', $output);
        $this->assertStringContainsString('grpc_server_handling_seconds_bucket', $output);
    }

    public function testHonoursInboundRequestIdMetadata(): void
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
                return 'ok';
            }
        };

        // gRPC metadata is array<string, string[]>; mirror an upstream X-Request-Id.
        $ctx = $this->createMock(ContextInterface::class);
        $ctx->method('getValue')->with('x-request-id')->willReturn(['upstream-grpc-id']);

        $this->invoke($this->makeInvoker($inner, $correlation), $ctx);

        $this->assertSame('upstream-grpc-id', $inner->idDuringCall);
    }

    public function testEmitsStructuredAccessLogForEveryCall(): void
    {
        $this->invoke($this->makeInvoker($this->okInvoker()));

        $this->assertTrue($this->logs->hasInfoThatContains('grpc call handled'));

        $record = $this->logs->getRecords()[0];
        $this->assertSame('Health', $record->context['grpc_method']);
        $this->assertSame('OK', $record->context['grpc_code']);
        $this->assertArrayHasKey('duration_seconds', $record->context);
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

        try {
            $this->invoke($this->makeInvoker($inner));
            $this->fail('GRPCException should propagate');
        } catch (GRPCException) {
            // expected — re-thrown so RoadRunner still maps it to a gRPC status
        }

        $this->assertStringContainsString('grpc_code="NOT_FOUND"', $this->render());
        // Client-side failures get the access log but NOT an error log (HTTP 4xx parity).
        $this->assertTrue($this->logs->hasInfoThatContains('grpc call handled'));
        $this->assertFalse($this->logs->hasErrorRecords());
    }

    public function testLogsErrorForInternalFailures(): void
    {
        $inner = new class implements InvokerInterface {
            #[\Override]
            public function invoke(
                ServiceInterface $service,
                Method $method,
                ContextInterface $ctx,
                ?string $input
            ): string {
                throw new \RuntimeException('boom');
            }
        };

        try {
            $this->invoke($this->makeInvoker($inner));
            $this->fail('the throwable should propagate');
        } catch (\RuntimeException) {
            // expected — re-thrown after recording
        }

        // Unexpected throwables map to INTERNAL (the gRPC analog of HTTP 500).
        $this->assertStringContainsString('grpc_code="INTERNAL"', $this->render());
        $this->assertTrue($this->logs->hasInfoThatContains('grpc call handled'));
        $this->assertTrue($this->logs->hasRecordThatContains('Unhandled gRPC exception: boom', Level::Error));

        $errorRecord = $this->logs->getRecords()[1];
        $this->assertSame('Health', $errorRecord->context['grpc_method']);
        $this->assertSame(\RuntimeException::class, $errorRecord->context['exception']);
        $this->assertArrayHasKey('trace', $errorRecord->context);
    }

    private function okInvoker(): InvokerInterface
    {
        return new class implements InvokerInterface {
            #[\Override]
            public function invoke(
                ServiceInterface $service,
                Method $method,
                ContextInterface $ctx,
                ?string $input
            ): string {
                return 'serialized-reply';
            }
        };
    }

    private function makeInvoker(InvokerInterface $inner, ?CorrelationContext $correlation = null): MeasuredInvoker
    {
        return new MeasuredInvoker(
            $inner,
            new PrometheusGrpcMetrics($this->registry, new GrpcStatusName()),
            new GrpcStatusName(),
            $correlation ?? new CorrelationContext(),
            new RandomCorrelationIdGenerator(),
            $this->logger
        );
    }

    private function invoke(MeasuredInvoker $invoker, ?ContextInterface $ctx = null): string
    {
        return $invoker->invoke(
            $this->createMock(ServiceInterface::class),
            $this->method(),
            $ctx ?? $this->createMock(ContextInterface::class),
            null
        );
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
