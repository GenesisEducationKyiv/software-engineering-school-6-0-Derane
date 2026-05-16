<?php

declare(strict_types=1);

namespace App\Cache;

use Predis\ClientInterface as RedisClientInterface;

/** @psalm-api */
final readonly class RedisGitHubCache implements GitHubCacheInterface
{
    public function __construct(
        private RedisClientInterface $redis
    ) {
    }

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->redis->get($key);
    }

    #[\Override]
    public function set(string $key, int $ttl, string $value): void
    {
        $this->redis->setex($key, $ttl, $value);
    }
}
