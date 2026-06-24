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
 * The synchronous welcome surfaces (REST controller, gRPC service) must resolve WITHOUT the
 * reply broker: if they resolved the async fail-closed RabbitWelcomeOutcomePublisher instead,
 * a reply-broker outage would map a successfully-sent welcome to UNAVAILABLE/503 and the
 * monolith's start-sweep would false-compensate the saga (cancel a subscription whose email
 * was already sent). RabbitConnection is overridden to throw, so a clean resolve proves the
 * sync surface never touched the broker.
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

        // Stub PDO so the welcome ledger + metrics store construct without Postgres.
        $container->set(\PDO::class, $this->createMock(\PDO::class));

        $container->set(RabbitConnection::class, factory(static function (): RabbitConnection {
            throw new \RuntimeException('a sync welcome surface must not resolve the reply broker');
        }));

        return $container;
    }
}
