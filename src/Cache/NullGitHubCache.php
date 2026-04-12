<?php

declare(strict_types=1);

namespace App\Cache;

class NullGitHubCache implements GitHubCacheInterface
{
    public function get(string $key): ?string
    {
        return null;
    }

    public function set(string $key, int $ttl, string $value): void
    {
    }
}
