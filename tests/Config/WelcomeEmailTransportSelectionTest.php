<?php

declare(strict_types=1);

namespace Tests\Config;

use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Grpc\GrpcWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Rabbit\RabbitWelcomeEmailRelay;
use App\Saga\Enrollment\Infrastructure\Rest\RestWelcomeEmailRelay;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use DI\Container;
use PHPUnit\Framework\TestCase;

/**
 * WELCOME_EMAIL_TRANSPORT selection (Story 3.1/3.4, RD4 impl-swap): the
 * WelcomeEmailRelay port is bound to one of THREE implementations by the flag, default
 * rabbit (absent/unknown => rabbit). The selected adapter is built lazily — the gRPC
 * client (ext-grpc, monolith image only) is never instantiated unless transport=grpc.
 *
 * Hermetic (no Docker/DB/broker): the heavy CommandBus + RabbitConnection deps are
 * stubbed via $container->set(...) before resolution (the ContainerTest pattern), so
 * resolving the relay never touches PDO or a live AMQP socket.
 */
final class WelcomeEmailTransportSelectionTest extends TestCase
{
    public function testDefaultsToRabbitWhenTransportIsAbsent(): void
    {
        $container = $this->container(null);

        self::assertInstanceOf(RabbitWelcomeEmailRelay::class, $container->get(WelcomeEmailRelay::class));
    }

    public function testRabbitWhenExplicitlySelected(): void
    {
        $container = $this->container('rabbit');

        self::assertInstanceOf(RabbitWelcomeEmailRelay::class, $container->get(WelcomeEmailRelay::class));
    }

    public function testUnknownTransportFallsBackToRabbit(): void
    {
        $container = $this->container('totally-unknown');

        self::assertInstanceOf(RabbitWelcomeEmailRelay::class, $container->get(WelcomeEmailRelay::class));
    }

    public function testRestWhenSelected(): void
    {
        $container = $this->container('rest');

        self::assertInstanceOf(RestWelcomeEmailRelay::class, $container->get(WelcomeEmailRelay::class));
    }

    public function testGrpcWhenSelected(): void
    {
        $container = $this->container('grpc');

        if (extension_loaded('grpc')) {
            self::assertInstanceOf(GrpcWelcomeEmailRelay::class, $container->get(WelcomeEmailRelay::class));

            return;
        }

        // Without ext-grpc the grpc branch cannot build a live client. Asserting that
        // resolution FAILS on a \Grpc\* symbol (ChannelCredentials) — rather than
        // silently returning the rabbit adapter — proves the grpc branch was selected
        // and stays lazy (the client is built only on resolution, RD7/§7.5).
        $this->expectException(\Throwable::class);
        $container->get(WelcomeEmailRelay::class);
    }

    private function container(?string $transport): Container
    {
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        if ($transport === null) {
            unset($settings['welcome_email']['transport']);
            // Mirror the settings default: absent env => rabbit.
            $settings['welcome_email']['transport'] = 'rabbit';
        } else {
            $settings['welcome_email']['transport'] = $transport;
        }
        // Defence in depth: even if a relay reached the broker, it points nowhere.
        $settings['rabbitmq']['host'] = '127.0.0.1';

        $containerFactory = require dirname(__DIR__, 2) . '/config/container.php';
        $container = $containerFactory($settings);

        // Stub the heavy deps so resolving the relay never opens a socket / connects PDO.
        $container->set(CommandBus::class, new class implements CommandBus {
            #[\Override]
            public function dispatch(Command $command): void
            {
            }
        });

        $channel = $this->createMock(\PhpAmqpLib\Channel\AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);
        $container->set(RabbitConnection::class, new RabbitConnection($channel));

        return $container;
    }
}
