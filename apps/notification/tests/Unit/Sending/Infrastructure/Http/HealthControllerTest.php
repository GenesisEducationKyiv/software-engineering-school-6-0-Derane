<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Http;

use App\Sending\Infrastructure\Health\HealthCheckInterface;
use App\Sending\Infrastructure\Http\HealthController;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class HealthControllerTest extends TestCase
{
    /** @var HealthCheckInterface&MockObject */
    private HealthCheckInterface $healthCheck;
    private HealthController $controller;

    #[\Override]
    protected function setUp(): void
    {
        $this->healthCheck = $this->createMock(HealthCheckInterface::class);
        $this->controller = new HealthController($this->healthCheck, new NullLogger());
    }

    public function testReturnsOkWhenChecksPass(): void
    {
        $this->healthCheck->expects(self::once())->method('check');

        $request = (new RequestFactory())->createRequest('GET', '/health');
        $response = (new ResponseFactory())->createResponse();

        $result = ($this->controller)($request, $response);

        self::assertSame(200, $result->getStatusCode());
        self::assertSame(['status' => 'ok'], json_decode((string) $result->getBody(), true));
    }

    public function testReturns503WhenAHealthCheckFails(): void
    {
        $this->healthCheck->method('check')->willThrowException(new \RuntimeException('broker down'));

        $request = (new RequestFactory())->createRequest('GET', '/health');
        $response = (new ResponseFactory())->createResponse();

        $result = ($this->controller)($request, $response);

        self::assertSame(503, $result->getStatusCode());
        self::assertSame(['status' => 'error'], json_decode((string) $result->getBody(), true));
    }
}
