<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\InProcessNullReleaseNotificationPublisher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class ContainerTest extends TestCase
{
    public function testLoggerWritesToStderrForWorkerSafety(): void
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);

        $logger = $container->get(LoggerInterface::class);

        self::assertInstanceOf(Logger::class, $logger);

        $handlers = $logger->getHandlers();

        self::assertCount(1, $handlers);
        self::assertInstanceOf(StreamHandler::class, $handlers[0]);
        self::assertSame('php://stderr', $handlers[0]->getUrl());
    }

    /**
     * C2: guards the binding-graph wiring PHP-DI errors are runtime-only about
     * (neither Psalm nor PHPCS catch a "doesn't actually resolve" mistake).
     * ReleaseNotificationPublisher is — for now — bound to the temporary stub
     * (InProcessNullReleaseNotificationPublisher), since C1's port has zero
     * production implementation and C5 hasn't introduced the RabbitMQ adapter
     * yet (see the stub's docblock). Resolved directly here — rather than via
     * WhenNewReleaseDetectedThenPublishReleaseEmails::class, whose dependency
     * chain reaches PdoSubscriptionRepository -> PDO and therefore needs the
     * docker stack — to keep this assertion in the env-independent Unit suite.
     */
    public function testReleaseNotificationPublisherResolvesToTheTemporaryStubAdapter(): void
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);

        $publisher = $container->get(ReleaseNotificationPublisher::class);

        self::assertInstanceOf(InProcessNullReleaseNotificationPublisher::class, $publisher);
    }
}
