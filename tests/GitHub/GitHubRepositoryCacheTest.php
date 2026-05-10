<?php

declare(strict_types=1);

namespace Tests\GitHub;

use App\GitHub\GitHubRepositoryCache;
use PHPUnit\Framework\TestCase;

final class GitHubRepositoryCacheTest extends TestCase
{
    public function testStoresRepositoryExistenceAsBoolean(): void
    {
        $backend = new ArrayGitHubCache();
        $cache = new GitHubRepositoryCache($backend, 60);

        self::assertNull($cache->getExists('acme/tool'));

        $cache->putExists('acme/tool', true);
        self::assertTrue($cache->getExists('acme/tool'));

        $cache->putExists('acme/old-tool', false);
        self::assertFalse($cache->getExists('acme/old-tool'));
    }
}
