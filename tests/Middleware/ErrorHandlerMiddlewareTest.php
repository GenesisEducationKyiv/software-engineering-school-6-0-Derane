<?php

declare(strict_types=1);

namespace Tests\Middleware;

use App\Exception\RateLimitException;
use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use App\Middleware\ErrorHandlerMiddleware;
use PHPUnit\Framework\TestCase;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class ErrorHandlerMiddlewareTest extends TestCase
{
    private ErrorHandlerMiddleware $middleware;

    protected function setUp(): void
    {
        $this->middleware = new ErrorHandlerMiddleware(new NullLogger(), new ResponseFactory());
    }

    private function createHandler(\Throwable $exception): RequestHandlerInterface
    {
        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willThrowException($exception);
        return $handler;
    }

    public function testPassesThroughOnSuccess(): void
    {
        $expectedResponse = (new ResponseFactory())->createResponse(200);

        $handler = $this->createMock(RequestHandlerInterface::class);
        $handler->method('handle')->willReturn($expectedResponse);

        $request = (new RequestFactory())->createRequest('GET', '/test');
        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testValidationExceptionReturns400(): void
    {
        $request = (new RequestFactory())->createRequest('GET', '/test');
        $handler = $this->createHandler(new ValidationException('Bad input'));

        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(400, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('Bad input', $body['error']);
    }

    public function testRepositoryNotFoundReturns404(): void
    {
        $request = (new RequestFactory())->createRequest('GET', '/test');
        $handler = $this->createHandler(new RepositoryNotFoundException('owner/repo'));

        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(404, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertStringContainsString('owner/repo', $body['error']);
    }

    public function testSubscriptionNotFoundReturns404(): void
    {
        $request = (new RequestFactory())->createRequest('GET', '/test');
        $handler = $this->createHandler(new SubscriptionNotFoundException(42));

        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(404, $result->getStatusCode());
    }

    public function testRateLimitExceptionReturns429(): void
    {
        $request = (new RequestFactory())->createRequest('GET', '/test');
        $handler = $this->createHandler(new RateLimitException('60'));

        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(429, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertStringContainsString('rate limit', strtolower($body['error']));
    }

    public function testUnhandledExceptionReturns500(): void
    {
        $request = (new RequestFactory())->createRequest('GET', '/test');
        $handler = $this->createHandler(new \RuntimeException('Something broke'));

        $result = $this->middleware->process($request, $handler);

        $this->assertEquals(500, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('Internal server error', $body['error']);
    }
}
