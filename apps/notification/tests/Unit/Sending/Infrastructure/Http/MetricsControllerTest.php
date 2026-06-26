<?php

declare(strict_types=1);

namespace Tests\Unit\Sending\Infrastructure\Http;

use App\Sending\Infrastructure\Http\MetricsController;
use App\Sending\Infrastructure\Metrics\MetricsServiceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

final class MetricsControllerTest extends TestCase
{
    /** @var MetricsServiceInterface&MockObject */
    private MetricsServiceInterface $metrics;

    #[\Override]
    protected function setUp(): void
    {
        $this->metrics = $this->createMock(MetricsServiceInterface::class);
    }

    public function testReturnsPrometheusContentType(): void
    {
        $this->metrics->method('collect')->willReturn(
            "# HELP notification_consumed_total Processed messages\n"
            . "# TYPE notification_consumed_total gauge\n"
            . "notification_consumed_total 5\n"
        );

        $controller = new MetricsController($this->metrics);
        $request = (new RequestFactory())->createRequest('GET', '/metrics');
        $response = (new ResponseFactory())->createResponse();

        $result = $controller($request, $response);

        self::assertSame('text/plain; version=0.0.4; charset=utf-8', $result->getHeaderLine('Content-Type'));
        self::assertStringContainsString('notification_consumed_total 5', (string) $result->getBody());
    }
}
