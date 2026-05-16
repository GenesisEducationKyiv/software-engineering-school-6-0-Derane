<?php

declare(strict_types=1);

namespace Tests\Integration\Cache;

use App\Cache\GitHubCacheInterface;
use Predis\Client as RedisClient;
use Tests\Integration\IntegrationTestCase;

final class RedisGitHubCacheTest extends IntegrationTestCase
{
    private GitHubCacheInterface $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = $this->c->get(GitHubCacheInterface::class);
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get($this->faker->uuid()));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $key = $this->cacheKey();
        $payload = $this->faker->sentence();

        $this->cache->set($key, 60, $payload);

        $this->assertSame($payload, $this->cache->get($key));
    }

    public function testSetAppliesTtl(): void
    {
        $key = $this->cacheKey();
        $this->cache->set($key, 120, $this->faker->sentence());

        $ttl = $this->c->get(RedisClient::class)->ttl($key);
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $key = $this->cacheKey();
        $first = $this->faker->sentence();
        $second = $this->faker->sentence();

        $this->cache->set($key, 60, $first);
        $this->cache->set($key, 60, $second);

        $this->assertSame($second, $this->cache->get($key));
    }

    private function cacheKey(): string
    {
        return 'repo:' . $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
