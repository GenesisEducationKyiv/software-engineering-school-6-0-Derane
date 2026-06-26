<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Notification\Publishing\Domain\ReleaseNotificationPublisher;
use App\Notification\Publishing\Infrastructure\RabbitReleaseNotificationPublisher;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
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
     * E1: cutover — verifies the application has correctly abandoned the temporary
     * stub adapter and bound the RabbitMQ publisher as the real notification path.
     */
    public function testReleaseNotificationPublisherResolvesToTheRabbitAdapter(): void
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);

        // We stub the RabbitConnection here because instantiating it through DI
        // triggers the real AMQPStreamConnection which would break this unit test.
        $channel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        $connection = new \App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection($channel);
        $container->set(\App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection::class, $connection);

        $publisher = $container->get(ReleaseNotificationPublisher::class);

        self::assertInstanceOf(RabbitReleaseNotificationPublisher::class, $publisher);
    }

    public function testListenerProviderResolutionDoesNotTouchRabbitUntilAnEventIsDispatched(): void
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        $settings['rabbitmq']['host'] = 'nonexistent.invalid';

        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);

        $provider = $container->get(ListenerProviderInterface::class);

        self::assertInstanceOf(ListenerProviderInterface::class, $provider);
    }
}
