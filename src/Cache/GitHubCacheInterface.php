<?php

declare(strict_types=1);

namespace App\Cache;

interface GitHubCacheInterface
{
    public function get(string $key): ?string;

    public function set(string $key, int $ttl, string $value): void;
}
