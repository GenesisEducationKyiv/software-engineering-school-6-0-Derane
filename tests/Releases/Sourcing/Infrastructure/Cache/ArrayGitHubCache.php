<?php

declare(strict_types=1);

namespace Tests\Releases\Sourcing\Infrastructure\Cache;

use App\Releases\Sourcing\Infrastructure\Cache\GitHubCacheInterface;

final class ArrayGitHubCache implements GitHubCacheInterface
{
    /** @var array<string, string> */
    private array $items = [];

    #[\Override]
    public function get(string $key): ?string
    {
        return $this->items[$key] ?? null;
    }

    #[\Override]
    public function set(string $key, int $ttl, string $value): void
    {
        $this->items[$key] = $value;
    }
}
