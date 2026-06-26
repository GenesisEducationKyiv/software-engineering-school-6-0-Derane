<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Migration\Migrator;
use DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Bootstraps the real DI container once per test class and resets ledger data between tests.
 * Must run inside the notification-svc dev container where notification-db, rabbitmq, and
 * mailhog are reachable by hostname.
 */
abstract class IntegrationTestCase extends TestCase
{
    private static ?Container $container = null;
    private static bool $migrated = false;

    protected Container $c;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c = self::container();

        $this->resetLedger();
    }

    protected static function container(): Container
    {
        if (self::$container !== null) {
            return self::$container;
        }

        self::populateEnv();

        /** @var array<string, mixed> $settings */
        $settings = require dirname(__DIR__, 2) . '/config/settings.php';
        /** @var callable(array<string, mixed>): Container $build */
        $build = require dirname(__DIR__, 2) . '/config/container.php';

        $container = $build($settings);

        if (!self::$migrated) {
            self::migrate($container->get(PDO::class));
            self::$migrated = true;
        }

        self::$container = $container;
        return $container;
    }

    private static function migrate(PDO $pdo): void
    {
        (new Migrator($pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    private static function populateEnv(): void
    {
        $keys = [
            'NOTIFICATION_DB_HOST', 'NOTIFICATION_DB_PORT', 'NOTIFICATION_DB_NAME',
            'NOTIFICATION_DB_USER', 'NOTIFICATION_DB_PASSWORD',
            'RABBITMQ_HOST', 'RABBITMQ_PORT', 'RABBITMQ_USER', 'RABBITMQ_PASSWORD', 'RABBITMQ_VHOST',
            'SMTP_HOST', 'SMTP_PORT', 'SMTP_USER', 'SMTP_PASSWORD', 'SMTP_FROM', 'SMTP_ENCRYPTION',
        ];

        foreach ($keys as $key) {
            if (!isset($_ENV[$key])) {
                $value = getenv($key);
                if ($value !== false) {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    private function resetLedger(): void
    {
        $this->c->get(PDO::class)->exec(
            'TRUNCATE release_notifications, notification_metrics RESTART IDENTITY CASCADE'
        );
    }

    protected function rabbitChannel(): \PhpAmqpLib\Channel\AMQPChannel
    {
        return $this->c->get(RabbitConnection::class)->channel();
    }
}
