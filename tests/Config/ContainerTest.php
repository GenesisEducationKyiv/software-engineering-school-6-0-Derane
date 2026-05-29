<?php

declare(strict_types=1);

namespace Tests\Config;

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
}
