<?php

declare(strict_types=1);

namespace Tests\GitHub;

use App\GitHub\GitHubApiClient;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class GitHubApiClientTest extends TestCase
{
    public function testGetRepositorySendsGitHubHeadersAndDecodesPayload(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['full_name' => 'golang/go'], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));

        $client = new GitHubApiClient(new Client(['handler' => $stack]), 'token-123');

        $payload = $client->getRepository('golang/go');

        self::assertSame('golang/go', $payload['full_name']);
        self::assertCount(1, $history);
        self::assertSame('https://api.github.com/repos/golang/go', (string) $history[0]['request']->getUri());
        self::assertSame('application/vnd.github.v3+json', $history[0]['request']->getHeaderLine('Accept'));
        self::assertSame('GitHub-Release-Notifier/1.0', $history[0]['request']->getHeaderLine('User-Agent'));
        self::assertSame('Bearer token-123', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testGetLatestReleaseDecodesReleasePayload(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['tag_name' => 'v1.2.3'], JSON_THROW_ON_ERROR)),
        ]));

        $client = new GitHubApiClient(new Client(['handler' => $stack]), '');

        $payload = $client->getLatestRelease('acme/tool');

        self::assertSame('v1.2.3', $payload['tag_name']);
    }

    public function testOmitsAuthorizationHeaderWhenTokenIsEmpty(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['full_name' => 'golang/go'], JSON_THROW_ON_ERROR)),
        ]));
        $stack->push(Middleware::history($history));

        $client = new GitHubApiClient(new Client(['handler' => $stack]), '');

        $client->getRepository('golang/go');

        self::assertCount(1, $history);
        self::assertSame('', $history[0]['request']->getHeaderLine('Authorization'));
    }

    public function testAllowsEmptyObjectPayloads(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode((object) [], JSON_THROW_ON_ERROR)),
        ]));

        $client = new GitHubApiClient(new Client(['handler' => $stack]), '');

        self::assertSame([], $client->getRepository('acme/tool'));
    }

    public function testRejectsNonEmptyJsonListPayloads(): void
    {
        $stack = HandlerStack::create(new MockHandler([
            new Response(200, [], json_encode(['not-an-object'], JSON_THROW_ON_ERROR)),
        ]));

        $client = new GitHubApiClient(new Client(['handler' => $stack]), '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub API returned a list JSON payload');

        $client->getRepository('acme/tool');
    }
}
