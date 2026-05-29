<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\RouteTagMiddleware;
use App\Observability\CorrelationContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Interfaces\RouteInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Routing\RouteContext;

class RouteTagMiddlewareTest extends TestCase
{
    public function testRecordsMatchedRoutePatternIntoContext(): void
    {
        $context = new CorrelationContext();
        $route = $this->createMock(RouteInterface::class);
        $route->method('getPattern')->willReturn('/api/subscriptions/{id}');

        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions/5')
            ->withAttribute(RouteContext::ROUTE, $route);

        (new RouteTagMiddleware($context))->process($request, $this->okHandler());

        $this->assertSame('/api/subscriptions/{id}', $context->route());
    }

    public function testLeavesRouteUnsetWhenNoRouteResolved(): void
    {
        $context = new CorrelationContext();

        $request = (new RequestFactory())->createRequest('GET', '/missing');
        (new RouteTagMiddleware($context))->process($request, $this->okHandler());

        $this->assertNull($context->route());
    }

    private function okHandler(): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

        return $handler;
    }
}
