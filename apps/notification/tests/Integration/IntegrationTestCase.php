<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use DI\Container;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * E2's minimal real-infrastructure harness for `apps/notification` — the
 * service's FIRST `tests/Integration` suite (constraint §2: there was no
 * precedent to slot into). Mirrors the shape of the monolith's
 * `Tests\Integration\IntegrationTestCase` (container bootstrap, env
 * population, per-test data reset) but scoped to exactly what THIS proof
 * needs — a real `\PDO` to `notification-db` and a real, topology-asserted
 * `RabbitConnection` to `rabbitmq` — both already wired by
 * `config/container.php` (D1/D4); this harness does not reimplement any
 * connection/topology logic, it only resolves the existing bindings.
 *
 * Deliberately NOT a general-purpose service integration framework
 * (Implementation focus §2's "do not over-build" instruction): no Faker (the
 * service has no such dependency — D1 kept it lean), no Redis (this service
 * has none), no Migrator class (the service's `bin/migrate.php` applies
 * `migrations/*.sql` directly via `\PDO::exec()` — this harness mirrors that
 * exact mechanism for its one-time bootstrap).
 *
 * Must run inside the `notification-svc` `dev`-target container (or
 * equivalent — anything on the compose network with `pdo_pgsql`/`amqp`
 * extensions and DNS-reachable `notification-db`/`rabbitmq`/`mailhog`
 * hostnames): the host has neither (see Dev Agent Record, Task 1) — exactly
 * mirroring how the monolith's `Integration` suite only runs inside
 * `make integration-run`'s `app` container, never on host.
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

    /**
     * Mirrors `bin/migrate.php`'s exact mechanism (glob + sort + PDO::exec) —
     * the service has no `Migrator` class (that is a monolith-only
     * abstraction; recreating one here for a single bootstrap call would be
     * exactly the "over-build a general-purpose framework" this harness must
     * avoid). All migrations are `CREATE TABLE IF NOT EXISTS` — idempotent,
     * safe to re-run against an already-migrated `notification-db`.
     */
    private static function migrate(PDO $pdo): void
    {
        $migrationFiles = glob(dirname(__DIR__, 2) . '/migrations/*.sql');
        if ($migrationFiles === false) {
            throw new \RuntimeException('Failed to enumerate notification migrations');
        }

        sort($migrationFiles);

        foreach ($migrationFiles as $migrationFile) {
            $sql = file_get_contents($migrationFile);
            if ($sql === false) {
                throw new \RuntimeException(sprintf('Failed to read migration file: %s', $migrationFile));
            }

            $pdo->exec($sql);
        }
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

    /**
     * Per-test reset of THIS proof's two data surfaces — `release_notifications`
     * (the ledger AC-1 counts rows in) and `notification_metrics` (written by
     * `MessageProcessingStatsRecorder`/`DeliveryOutcomeRecorder` on every
     * `handleDelivery()` — left untruncated it would accumulate counters
     * across tests/runs without affecting THIS proof's assertions, but
     * truncating keeps each test's `release_notifications` state — the one
     * table AC-1 actually asserts against — deterministic and isolated,
     * mirroring the monolith `IntegrationTestCase::truncateDataTables()`'s
     * "reset what the suite asserts against" intent).
     */
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
