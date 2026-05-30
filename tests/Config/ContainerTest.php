<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Application\Event\EventPublisherInterface;
use App\Application\Event\ReleaseNotificationSent;
use App\Controller\MetricsController;
use App\Middleware\ApiKeyMiddleware;
use App\Middleware\CorrelationIdMiddleware;
use App\Middleware\ErrorHandlerMiddleware;
use App\Middleware\RequestMetricsMiddleware;
use App\Middleware\RouteTagMiddleware;
use App\Observability\Event\ApplicationEventLogger;
use App\Service\NotifierInterface;
use DI\Container;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Spiral\RoadRunner\GRPC\InvokerInterface;

final class ContainerTest extends TestCase
{
    public function testLoggerWritesToStderrForWorkerSafety(): void
    {
        $logger = $this->container()->get(LoggerInterface::class);

        self::assertInstanceOf(Logger::class, $logger);

        $handlers = $logger->getHandlers();

        self::assertCount(1, $handlers);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]);
        self::assertSame('php://stderr', $handlers[0]->getUrl());
    }

    /**
     * Smoke test for the wiring resolvable without a database, so a stale DI key
     * (like the EventDispatcherInterface -> EventPublisherInterface regression)
     * fails CI instead of only blowing up at runtime. DB-backed bindings are
     * covered by {@see \Tests\Integration\ContainerBindingsTest}.
     */
    public function testCriticalDatabaselessBindingsResolve(): void
    {
        $container = $this->container();

        $bindings = [
            EventPublisherInterface::class,
            NotifierInterface::class,
            ErrorHandlerMiddleware::class,
            ApiKeyMiddleware::class,
            CorrelationIdMiddleware::class,
            RequestMetricsMiddleware::class,
            RouteTagMiddleware::class,
            MetricsController::class,
            InvokerInterface::class,
        ];

        foreach ($bindings as $id) {
            self::assertIsObject($container->get($id), "{$id} should resolve");
        }
    }

    public function testApplicationEventPiiIsRedactedThroughTheRealLoggerComposition(): void
    {
        /** @var Logger $logger */
        $logger = $this->container()->get(LoggerInterface::class);
        $captured = new TestHandler();
        $logger->pushHandler($captured);

        (new ApplicationEventLogger($logger))(
            new ReleaseNotificationSent('user@example.com', 'golang/go', 'v1.22')
        );

        $records = $captured->getRecords();
        self::assertCount(1, $records);

        $json = (new JsonFormatter())->format($records[0]);
        self::assertStringNotContainsString('user@example.com', $json);
        self::assertStringContainsString('u***@example.com', $json);
    }

    private function container(): Container
    {
        /** @var array<string, mixed> $settings */
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        /** @var callable(array<string, mixed>): Container $build */
        $build = require dirname(__DIR__, 2) . '/config/container.php';

        return $build($settings);
    }
}
