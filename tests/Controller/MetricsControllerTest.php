<?php

declare(strict_types=1);

namespace Tests\Controller;

use App\Controller\HealthController;
use App\Controller\MetricsController;
use App\Health\HealthCheckInterface;
use App\Service\MetricsServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

class MetricsControllerTest extends TestCase
{
    public function testMetricsReturnsPrometheusFormat(): void
    {
        $metricsService = $this->createMock(MetricsServiceInterface::class);
        $metricsService->method('collect')->willReturn(
            "# HELP app_subscriptions_total Total\n# TYPE app_subscriptions_total gauge\napp_subscriptions_total 5\n"
            . "# HELP app_info Application info\n# TYPE app_info gauge\napp_info{version=\"1.0.0\"} 1\n"
        );

        $controller = new MetricsController($metricsService);

        $request = (new RequestFactory())->createRequest('GET', '/metrics');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller($request, $response);

        $body = (string) $result->getBody();

        $this->assertStringContainsString('# TYPE app_subscriptions_total gauge', $body);
        $this->assertStringContainsString('app_info{version="1.0.0"} 1', $body);
        $this->assertEquals('text/plain; version=0.0.4; charset=utf-8', $result->getHeaderLine('Content-Type'));
    }

    public function testHealthReturnsOk(): void
    {
        $healthCheck = $this->createMock(HealthCheckInterface::class);
        $healthCheck->expects($this->once())->method('check');

        $controller = new HealthController($healthCheck, new NullLogger());

        $request = (new RequestFactory())->createRequest('GET', '/health');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller($request, $response);

        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('ok', $body['status']);
        $this->assertEquals(200, $result->getStatusCode());
    }

    public function testHealthReturns503OnDbFailure(): void
    {
        $healthCheck = $this->createMock(HealthCheckInterface::class);
        $healthCheck->method('check')->willThrowException(new \RuntimeException('Connection refused'));

        $controller = new HealthController($healthCheck, new NullLogger());

        $request = (new RequestFactory())->createRequest('GET', '/health');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller($request, $response);

        $this->assertEquals(503, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertEquals('error', $body['status']);
        $this->assertArrayNotHasKey('message', $body);
    }
}
