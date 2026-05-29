<?php

declare(strict_types=1);

namespace Tests\GitHub;

use App\Domain\Factory\ReleaseFactory;
use App\Domain\Release;
use App\GitHub\GitHubReleaseCache;
use PHPUnit\Framework\TestCase;

final class GitHubReleaseCacheTest extends TestCase
{
    public function testStoresReleasePayloadsAsReleaseObjects(): void
    {
        $backend = new ArrayGitHubCache();
        $cache = new GitHubReleaseCache($backend, new ReleaseFactory(), 60);
        $release = new Release('v1.2.3', 'Release', 'https://github.com/acme/tool/releases/v1.2.3', '2026-05-10', '');

        self::assertNull($cache->getLatestRelease('acme/tool'));

        $cache->putLatestRelease('acme/tool', $release);
        $cached = $cache->getLatestRelease('acme/tool');

        self::assertNotNull($cached);
        self::assertSame('v1.2.3', $cached->tagName);
        self::assertSame('Release', $cached->name);
    }

    public function testRoundTripsReleaseWithoutTagName(): void
    {
        $backend = new ArrayGitHubCache();
        $cache = new GitHubReleaseCache($backend, new ReleaseFactory(), 60);
        $release = new Release(
            null,
            'Draftless release',
            'https://github.com/acme/tool/releases/latest',
            '2026-05-10',
            ''
        );

        $cache->putLatestRelease('acme/tool', $release);
        $cached = $cache->getLatestRelease('acme/tool');

        self::assertNotNull($cached);
        self::assertNull($cached->tagName);
        self::assertSame('Draftless release', $cached->name);
    }
}
