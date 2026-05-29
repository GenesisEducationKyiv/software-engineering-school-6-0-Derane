<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Exception\ExceptionStatusMap;
use App\Exception\ValidationException;
use App\Middleware\RequestMetricsMiddleware;
use App\Observability\Metrics\PrometheusHttpMetrics;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;
use Prometheus\Storage\InMemory;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class RequestMetricsMiddlewareTest extends TestCase
{
    private CollectorRegistry $registry;
    private RequestMetricsMiddleware $middleware;

    protected function setUp(): void
    {
        $this->registry = new CollectorRegistry(new InMemory(), false);
        $this->middleware = new RequestMetricsMiddleware(
            new PrometheusHttpMetrics($this->registry),
            new ExceptionStatusMap(),
            new NullLogger()
        );
    }

    public function testRecordsCounterAndDurationLabelledByRouteAndStatus(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse(201));

        $request = (new RequestFactory())->createRequest('POST', '/api/subscriptions')
            ->withAttribute(RouteContext::ROUTE, $this->route('/api/subscriptions'));

        $this->middleware->process($request, $handler);

        $output = $this->render();
        $this->assertStringContainsString('http_requests_total{', $output);
        $this->assertStringContainsString('method="POST"', $output);
        $this->assertStringContainsString('route="/api/subscriptions"', $output);
        $this->assertStringContainsString('status="201"', $output);
        $this->assertStringContainsString('http_request_duration_seconds_bucket', $output);
    }

    public function testThrownExceptionIsLabelledWithMappedStatusAndRethrown(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException(new ValidationException('bad input'));

        $request = (new RequestFactory())->createRequest('POST', '/api/subscriptions')
            ->withAttribute(RouteContext::ROUTE, $this->route('/api/subscriptions'));

        $this->expectException(ValidationException::class);

        try {
            $this->middleware->process($request, $handler);
        } finally {
            $this->assertStringContainsString('status="400"', $this->render());
        }
    }

    public function testUnmatchedRouteFallsBackToSentinelLabel(): void
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse(200));

        $request = (new RequestFactory())->createRequest('GET', '/nope');

        $this->middleware->process($request, $handler);

        $this->assertStringContainsString('route="unmatched"', $this->render());
    }

    private function render(): string
    {
        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }

    private function route(string $pattern): RouteInterface
    {
        $route = $this->createMock(RouteInterface::class);
        $route->method('getPattern')->willReturn($pattern);

        return $route;
    }
}
