<?php

declare(strict_types=1);

namespace App\Cache;

/** @psalm-api */
final readonly class NullGitHubCache implements GitHubCacheInterface
{
    #[\Override]
    public function get(string $key): ?string
    {
        return null;
    }

    #[\Override]
    public function set(string $key, int $ttl, string $value): void
    {
    }
}
