<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\RequestMetricsMiddleware;
use App\Observability\CorrelationContext;
use App\Observability\Metrics\PrometheusHttpMetrics;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class RequestMetricsMiddlewareTest extends TestCase
{
    private CollectorRegistry $registry;
    private CorrelationContext $context;
    private RequestMetricsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->context = new CorrelationContext();
        $this->middleware = new RequestMetricsMiddleware(
            new PrometheusHttpMetrics($this->registry),
            $this->context,
            new NullLogger()
        );
    }

    public function testRecordsRouteFromContextAndStatusFromResponse(): void
    {
        $this->context->setRoute('/api/subscriptions');

        $this->middleware->process($this->request('POST'), $this->handlerReturning(201));

        $out = $this->render();
        $this->assertStringContainsString('http_requests_total{', $out);
        $this->assertStringContainsString('method="POST"', $out);
        $this->assertStringContainsString('route="/api/subscriptions"', $out);
        $this->assertStringContainsString('status="201"', $out);
        $this->assertStringContainsString(
            'http_request_duration_seconds_bucket{method="POST",route="/api/subscriptions",status="201"',
            $out
        );
    }

    public function testCountsRoutingFailureResponsesAsUnmatched(): void
    {
        // Router raised 404 before RouteTagMiddleware ran, so no route is set; the
        // error handler produced a 404 response. RED must still count it.
        $this->middleware->process($this->request('GET'), $this->handlerReturning(404));

        $out = $this->render();
        $this->assertStringContainsString('route="unmatched"', $out);
        $this->assertStringContainsString('status="404"', $out);
    }

    public function testRecordsServerErrorAndRethrowsWhenHandlerThrows(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new \RuntimeException('boom'));

        try {
            $this->middleware->process($this->request('GET'), $handler);
            $this->fail('exception should propagate');
        } catch (\RuntimeException) {
            // expected — re-thrown after recording
        }

        $this->assertStringContainsString('status="500"', $this->render());
    }

    private function request(string $method): \Psr\Http\Message\ServerRequestInterface
    {
        return (new RequestFactory())->createRequest($method, '/x');
    }

    private function handlerReturning(int $status): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse($status));

        return $handler;
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
