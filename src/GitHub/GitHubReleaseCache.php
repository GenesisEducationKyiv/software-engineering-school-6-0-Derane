<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Cache\GitHubCacheInterface;
use App\Domain\Factory\ReleaseFactoryInterface;
use App\Domain\Release;

/** @psalm-api */
final readonly class GitHubReleaseCache implements LatestReleaseCacheInterface
{
    public function __construct(
        private GitHubCacheInterface $cache,
        private ReleaseFactoryInterface $releaseFactory,
        private int $ttl
    ) {
    }

    #[\Override]
    public function getLatestRelease(string $repository): ?Release
    {
        $cached = $this->cache->get($this->key($repository));
        if ($cached === null) {
            return null;
        }

        $payload = json_decode($cached, true);
        if (!is_array($payload)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $this->releaseFactory->fromGitHubPayload($payload);
    }

    #[\Override]
    public function putLatestRelease(string $repository, Release $release): void
    {
        $this->cache->set(
            $this->key($repository),
            $this->ttl,
            json_encode($release->toArray(), JSON_THROW_ON_ERROR)
        );
    }

    private function key(string $repository): string
    {
        return "github:latest_release:{$repository}";
    }
}
