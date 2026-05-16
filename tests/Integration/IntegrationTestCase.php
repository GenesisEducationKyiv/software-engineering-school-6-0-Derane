<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Migration\Migrator;
use App\Service\GitHubServiceInterface;
use DI\Container;
use Faker\Factory as FakerFactory;
use Faker\Generator;
use PDO;
use PHPUnit\Framework\TestCase;
use Predis\Client as RedisClient;

abstract class IntegrationTestCase extends TestCase
{
    private static ?Container $container = null;
    private static bool $migrated = false;

    protected Container $c;
    protected Generator $faker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->c = self::container();
        $this->faker = FakerFactory::create();

        $this->truncateDataTables();
        $this->flushRedis();
    }

    /**
     * Override an environment integration with a custom stub for a single test.
     * Useful when a test needs different behaviour than the global stub
     * (e.g. simulating a missing GitHub repository).
     */
    protected function override(string $id, object $instance): void
    {
        $this->c->set($id, $instance);
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
        $container->set(GitHubServiceInterface::class, self::stubGitHubService());

        if (!self::$migrated) {
            $container->get(Migrator::class)->migrate();
            self::$migrated = true;
        }

        self::$container = $container;
        return $container;
    }

    private static function stubGitHubService(): GitHubServiceInterface
    {
        return new class implements GitHubServiceInterface {
            public function repositoryExists(string $repository): bool
            {
                return true;
            }

            public function getLatestRelease(string $repository): ?array
            {
                return null;
            }
        };
    }

    private static function populateEnv(): void
    {
        $keys = ['DB_HOST', 'DB_PORT', 'DB_NAME', 'DB_USER', 'DB_PASSWORD',
            'REDIS_HOST', 'REDIS_PORT', 'REDIS_CACHE_TTL', 'GITHUB_TOKEN',
            'GITHUB_SCAN_INTERVAL', 'GITHUB_SCAN_BATCH_SIZE', 'API_KEY'];

        foreach ($keys as $key) {
            if (!isset($_ENV[$key])) {
                $value = getenv($key);
                if ($value !== false) {
                    $_ENV[$key] = $value;
                }
            }
        }
    }

    private function truncateDataTables(): void
    {
        $this->c->get(PDO::class)->exec(
            'TRUNCATE subscriptions, repositories, release_notifications RESTART IDENTITY CASCADE'
        );
    }

    private function flushRedis(): void
    {
        try {
            $this->c->get(RedisClient::class)->flushdb();
        } catch (\Throwable) {
            // Redis is optional for some tests
        }
    }
}
