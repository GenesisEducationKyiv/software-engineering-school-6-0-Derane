<?php

declare(strict_types=1);

namespace Tests\Integration\Service;

use App\Domain\Factory\ReleaseFactory;
use App\GitHub\LatestReleaseCacheInterface;
use App\GitHub\RepositoryExistenceCacheInterface;
use App\Service\GitHubService;
use Predis\Client as RedisClient;
use Psr\Log\NullLogger;
use Tests\Integration\IntegrationTestCase;
use Tests\Support\SpyGitHubApiClient;

/**
 * Use-case integration test for the GitHub release cache: it exercises the full
 * caching slice (GitHubService -> LatestReleaseCacheInterface -> RedisGitHubCache
 * -> real Redis -> ReleaseFactory) against a live Redis, with only the GitHub API
 * boundary replaced by a counting spy. Proves the cache short-circuits the API,
 * survives a fresh service instance, and applies a TTL.
 */
final class GitHubServiceCacheTest extends IntegrationTestCase
{
    public function testSecondLatestReleaseLookupIsServedFromRedisNotApi(): void
    {
        $repository = $this->repositoryName();
        $api = $this->spyReturning($repository);
        $service = $this->serviceWith($api);

        $first = $service->getLatestRelease($repository);
        $second = $service->getLatestRelease($repository);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame('v1.4.0', $first->tagName);
        self::assertSame($first->tagName, $second->tagName);
        self::assertSame(1, $api->getLatestReleaseCalls, 'second lookup must come from the cache');
    }

    public function testCachedReleaseSurvivesAFreshServiceInstanceViaRedis(): void
    {
        $repository = $this->repositoryName();

        $warmApi = $this->spyReturning($repository);
        $this->serviceWith($warmApi)->getLatestRelease($repository);
        self::assertSame(1, $warmApi->getLatestReleaseCalls);

        // A brand-new service with a brand-new API spy: Redis is the only shared
        // state, so a cache hit here proves the value really round-tripped Redis.
        $coldApi = $this->spyReturning($repository);
        $cached = $this->serviceWith($coldApi)->getLatestRelease($repository);

        self::assertNotNull($cached);
        self::assertSame('v1.4.0', $cached->tagName);
        self::assertSame('Release 1.4.0', $cached->name);
        self::assertSame(0, $coldApi->getLatestReleaseCalls, 'value must be read back from Redis');
    }

    public function testCachedReleaseKeyReceivesPositiveTtlInRedis(): void
    {
        $repository = $this->repositoryName();
        $this->serviceWith($this->spyReturning($repository))->getLatestRelease($repository);

        $ttl = $this->c->get(RedisClient::class)->ttl("github:latest_release:{$repository}");

        self::assertGreaterThan(0, $ttl, 'cached releases must expire, not persist forever');
        self::assertLessThanOrEqual((int) ($_ENV['REDIS_CACHE_TTL'] ?? 600), $ttl);
    }

    private function serviceWith(SpyGitHubApiClient $api): GitHubService
    {
        return new GitHubService(
            $api,
            $this->c->get(RepositoryExistenceCacheInterface::class),
            $this->c->get(LatestReleaseCacheInterface::class),
            new ReleaseFactory(),
            new NullLogger()
        );
    }

    private function spyReturning(string $repository): SpyGitHubApiClient
    {
        return new SpyGitHubApiClient([
            'tag_name' => 'v1.4.0',
            'name' => 'Release 1.4.0',
            'html_url' => "https://github.com/{$repository}/releases/tag/v1.4.0",
            'published_at' => '2026-05-10T00:00:00Z',
            'body' => 'release notes',
        ]);
    }

    private function repositoryName(): string
    {
        return $this->faker->unique()->userName() . '/' . $this->faker->unique()->userName();
    }
}
