<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\CorrelationIdMiddleware;
use App\Observability\CorrelationContext;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class CorrelationIdMiddlewareTest extends TestCase
{
    public function testGeneratesIdBindsItDuringHandlingAndEchoesItBack(): void
    {
        $context = new CorrelationContext();
        $idDuringHandling = null;

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturnCallback(
            function () use ($context, &$idDuringHandling) {
                $idDuringHandling = $context->id();
                return (new ResponseFactory())->createResponse();
            }
        );

        $request = (new RequestFactory())->createRequest('GET', '/health');
        $response = (new CorrelationIdMiddleware($context))->process($request, $handler);

        $this->assertNotNull($idDuringHandling);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $idDuringHandling);
        $this->assertSame($idDuringHandling, $response->getHeaderLine('X-Request-Id'));
        $this->assertNull($context->id(), 'context must be reset after the request');
    }

    public function testHonoursInboundRequestIdHeader(): void
    {
        $context = new CorrelationContext();

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn((new ResponseFactory())->createResponse());

        $request = (new RequestFactory())->createRequest('GET', '/health')
            ->withHeader('X-Request-Id', 'upstream-trace-id');

        $response = (new CorrelationIdMiddleware($context))->process($request, $handler);

        $this->assertSame('upstream-trace-id', $response->getHeaderLine('X-Request-Id'));
    }
}
