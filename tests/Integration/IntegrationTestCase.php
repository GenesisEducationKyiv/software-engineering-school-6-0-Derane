<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migration\Migrator;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;
use Psr\Log\NullLogger;

abstract class IntegrationTestCase extends TestCase
{
    private static ?PDO $pdo = null;
    private static ?RedisClient $redis = null;
    private static bool $migrated = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncateDataTables();
        $this->flushRedis();
    }

    protected static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: 'localhost';
            $port = getenv('DB_PORT') ?: '5432';
            $name = getenv('DB_NAME') ?: 'release_notifier';
            $user = getenv('DB_USER') ?: 'app';
            $pass = getenv('DB_PASSWORD') ?: 'secret';

            self::$pdo = new PDO(
                "pgsql:host={$host};port={$port};dbname={$name}",
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        if (!self::$migrated) {
            $migrationsPath = dirname(__DIR__, 2) . '/migrations';
            (new Migrator(self::$pdo, $migrationsPath, new NullLogger()))->migrate();
            self::$migrated = true;
        }

        return self::$pdo;
    }

    protected static function redis(): RedisClient
    {
        if (self::$redis === null) {
            self::$redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => getenv('REDIS_HOST') ?: 'localhost',
                'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            ]);
        }

        return self::$redis;
    }

    private function truncateDataTables(): void
    {
        self::pdo()->exec(
            'TRUNCATE subscriptions, repositories, release_notifications RESTART IDENTITY CASCADE'
        );
    }

    private function flushRedis(): void
    {
        try {
            self::redis()->flushdb();
        } catch (\Throwable) {
            // Redis is optional for some tests
        }
    }
}
