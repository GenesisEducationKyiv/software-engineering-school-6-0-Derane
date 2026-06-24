<?php

declare(strict_types=1);

namespace Tests\Unit\Config;

use App\Sending\Infrastructure\Grpc\WelcomeEmailGrpcService;
use App\Sending\Infrastructure\Http\WelcomeEmailController;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use DI\Container;
use Notification\Welcome\V1\WelcomeEmailServiceInterface;
use PHPUnit\Framework\TestCase;

use function DI\factory;

/**
 * Finding-1 guard (ADR-0004): the SYNCHRONOUS welcome surfaces (the REST controller and
 * the gRPC service) must resolve WITHOUT the reply broker, because their handler is built
 * with the {@see \App\Sending\Infrastructure\Sync\NoOpWelcomeOutcomePublisher}. Were they
 * to resolve the async fail-closed RabbitWelcomeOutcomePublisher instead, a reply-broker
 * outage would map a SUCCESSFULLY-sent welcome to UNAVAILABLE/503 and the monolith's
 * start-sweep would false-compensate the saga (cancel a subscription whose email was sent).
 *
 * Hermetic and fast: PDO is mocked (the welcome ledger constructs without Postgres) and the
 * RabbitConnection definition is overridden with a factory that THROWS on resolution. A
 * clean resolve therefore PROVES the sync surface never touched the broker; a regression
 * that re-points it at the rabbit-backed handler would resolve RabbitConnection and fail.
 */
final class SyncWelcomeSurfacesAreBrokerIndependentTest extends TestCase
{
    public function testGrpcServiceResolvesWithoutResolvingTheReplyBroker(): void
    {
        self::assertInstanceOf(
            WelcomeEmailGrpcService::class,
            $this->brokerlessContainer()->get(WelcomeEmailServiceInterface::class),
        );
    }

    public function testRestControllerResolvesWithoutResolvingTheReplyBroker(): void
    {
        self::assertInstanceOf(
            WelcomeEmailController::class,
            $this->brokerlessContainer()->get(WelcomeEmailController::class),
        );
    }

    private function brokerlessContainer(): Container
    {
        // require (not require_once) so each test re-evaluates the closure the file returns.
        $settings = require dirname(__DIR__, 3) . '/config/settings.php';
        $containerFactory = require dirname(__DIR__, 3) . '/config/container.php';
        $container = $containerFactory($settings);

        // The welcome ledger + metrics store need a PDO; stub it so no Postgres is required.
        // \PDO (global) — both the DI key in config/container.php and the mocked type.
        $container->set(\PDO::class, $this->createMock(\PDO::class));

        // Any attempt to resolve the reply broker throws — so a successful resolve of a sync
        // surface proves it does NOT depend on the broker (the NoOpWelcomeOutcomePublisher fix).
        $container->set(RabbitConnection::class, factory(static function (): RabbitConnection {
            throw new \RuntimeException('a sync welcome surface must not resolve the reply broker');
        }));

        return $container;
    }
}
