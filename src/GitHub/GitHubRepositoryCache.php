<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Cache\GitHubCacheInterface;

/** @psalm-api */
final readonly class GitHubRepositoryCache implements RepositoryExistenceCacheInterface
{
    public function __construct(
        private GitHubCacheInterface $cache,
        private int $ttl
    ) {
    }

    #[\Override]
    public function getExists(string $repository): ?bool
    {
        $cached = $this->cache->get($this->key($repository));
        if ($cached === null) {
            return null;
        }

        return $cached === '1';
    }

    #[\Override]
    public function putExists(string $repository, bool $exists): void
    {
        $this->cache->set($this->key($repository), $this->ttl, $exists ? '1' : '0');
    }

    private function key(string $repository): string
    {
        return "github:repo_exists:{$repository}";
    }
}
