<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Middleware\ApiKeyMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Response;

class ApiKeyMiddlewareTest extends TestCase
{
    private function createHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $response = new Response();
                $response->getBody()->write('ok');
                return $response;
            }
        };
    }

    public function testSkipsAuthWhenNoApiKeyConfigured(): void
    {
        $middleware = new ApiKeyMiddleware('', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAllowsHealthEndpointWithoutKey(): void
    {
        $middleware = new ApiKeyMiddleware('secret-key', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/health');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testAllowsMetricsEndpointWithoutKey(): void
    {
        $middleware = new ApiKeyMiddleware('secret-key', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/metrics');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testReturns401WhenNoKeyProvided(): void
    {
        $middleware = new ApiKeyMiddleware('secret-key', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(401, $response->getStatusCode());
    }

    public function testReturns403WhenInvalidKey(): void
    {
        $middleware = new ApiKeyMiddleware('secret-key', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions')
            ->withHeader('X-API-Key', 'wrong-key');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(403, $response->getStatusCode());
    }

    public function testAllowsRequestWithValidKey(): void
    {
        $middleware = new ApiKeyMiddleware('secret-key', new ResponseFactory());
        $request = (new RequestFactory())->createRequest('GET', '/api/subscriptions')
            ->withHeader('X-API-Key', 'secret-key');
        $handler = $this->createHandler();

        $response = $middleware->process($request, $handler);
        $this->assertEquals(200, $response->getStatusCode());
    }
}
