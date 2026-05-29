<?php

declare(strict_types=1);

namespace Tests\Service;

use App\Cache\NullGitHubCache;
use App\Domain\Factory\ReleaseFactory;
use App\Exception\RateLimitException;
use App\GitHub\GitHubApiClient;
use App\GitHub\GitHubReleaseCache;
use App\GitHub\GitHubRepositoryCache;
use App\Service\GitHubService;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class GitHubServiceTest extends TestCase
{
    private function createServiceWithMock(array $responses): GitHubService
    {
        $mock = new MockHandler($responses);
        $handler = HandlerStack::create($mock);
        $client = new Client(['handler' => $handler]);

        $cache = new NullGitHubCache();
        $releaseFactory = new ReleaseFactory();

        return new GitHubService(
            new GitHubApiClient($client),
            new GitHubRepositoryCache($cache, 600),
            new GitHubReleaseCache($cache, $releaseFactory, 600),
            $releaseFactory,
            new NullLogger()
        );
    }

    public function testRepositoryExistsReturnsTrue(): void
    {
        $service = $this->createServiceWithMock([
            new Response(200, [], json_encode(['full_name' => 'golang/go'])),
        ]);

        $this->assertTrue($service->repositoryExists('golang/go'));
    }

    public function testRepositoryExistsReturnsFalse(): void
    {
        $service = $this->createServiceWithMock([
            new ClientException(
                'Not Found',
                new Request('GET', '/repos/nonexistent/repo'),
                new Response(404, [], json_encode(['message' => 'Not Found']))
            ),
        ]);

        $this->assertFalse($service->repositoryExists('nonexistent/repo'));
    }

    public function testGetLatestReleaseSuccess(): void
    {
        $releaseData = [
            'tag_name' => 'v1.22.0',
            'name' => 'Go 1.22',
            'html_url' => 'https://github.com/golang/go/releases/tag/v1.22.0',
            'published_at' => '2024-02-06T00:00:00Z',
            'body' => 'Release notes here',
        ];

        $service = $this->createServiceWithMock([
            new Response(200, [], json_encode($releaseData)),
        ]);

        $result = $service->getLatestRelease('golang/go');

        $this->assertNotNull($result);
        $this->assertEquals('v1.22.0', $result->tagName);
        $this->assertEquals('Go 1.22', $result->name);
    }

    public function testGetLatestReleaseNotFound(): void
    {
        $service = $this->createServiceWithMock([
            new ClientException(
                'Not Found',
                new Request('GET', '/repos/test/repo/releases/latest'),
                new Response(404, [], json_encode(['message' => 'Not Found']))
            ),
        ]);

        $result = $service->getLatestRelease('test/repo');
        $this->assertNull($result);
    }

    public function testRepositoryExistsRateLimited(): void
    {
        $service = $this->createServiceWithMock([
            new ClientException(
                'Too Many Requests',
                new Request('GET', '/repos/golang/go'),
                new Response(429, ['Retry-After' => '30'], json_encode(['message' => 'rate limit']))
            ),
        ]);

        $this->expectException(RateLimitException::class);

        $service->repositoryExists('golang/go');
    }

    public function testGetLatestReleaseRateLimited(): void
    {
        $service = $this->createServiceWithMock([
            new ClientException(
                'Too Many Requests',
                new Request('GET', '/repos/test/repo/releases/latest'),
                new Response(429, ['Retry-After' => '60'], json_encode(['message' => 'rate limit']))
            ),
        ]);

        $this->expectException(RateLimitException::class);

        $service->getLatestRelease('test/repo');
    }
}
