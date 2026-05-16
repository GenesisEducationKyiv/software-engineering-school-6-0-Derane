<?php

declare(strict_types=1);

namespace Tests\Integration\Cache;

use App\Cache\RedisGitHubCache;
use Psr\Log\NullLogger;
use Tests\Integration\IntegrationTestCase;

final class RedisGitHubCacheTest extends IntegrationTestCase
{
    private RedisGitHubCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new RedisGitHubCache(self::redis(), new NullLogger());
    }

    public function testGetReturnsNullForMissingKey(): void
    {
        $this->assertNull($this->cache->get('missing-key'));
    }

    public function testSetThenGetRoundTrip(): void
    {
        $this->cache->set('repo:golang/go', 60, '{"id": 1}');

        $this->assertSame('{"id": 1}', $this->cache->get('repo:golang/go'));
    }

    public function testSetAppliesTtl(): void
    {
        $this->cache->set('repo:golang/go', 120, 'payload');

        $ttl = self::redis()->ttl('repo:golang/go');
        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(120, $ttl);
    }

    public function testSetOverwritesExistingValue(): void
    {
        $this->cache->set('repo:golang/go', 60, 'first');
        $this->cache->set('repo:golang/go', 60, 'second');

        $this->assertSame('second', $this->cache->get('repo:golang/go'));
    }
}
