<?php

declare(strict_types=1);

namespace App\Service;

use App\Cache\GitHubCacheInterface;
use App\Exception\RateLimitException;
use Fig\Http\Message\StatusCodeInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class GitHubService implements GitHubServiceInterface
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private ClientInterface $httpClient,
        private GitHubCacheInterface $cache,
        private LoggerInterface $logger,
        private string $token = '',
        private int $cacheTtl = 600
    ) {
    }

    #[\Override]
    public function repositoryExists(string $repository): bool
    {
        $cacheKey = "github:repo_exists:{$repository}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached === '1';
        }

        try {
            $this->request('GET', "/repos/{$repository}");
            $this->cache->set($cacheKey, $this->cacheTtl, '1');
            return true;
        } catch (ClientException $e) {
            $statusCode = $e->getResponse()->getStatusCode();
            if ($statusCode === StatusCodeInterface::STATUS_NOT_FOUND) {
                $this->cache->set($cacheKey, $this->cacheTtl, '0');
                return false;
            }
            if ($statusCode === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repository}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }

    #[\Override]
    public function getLatestRelease(string $repository): ?array
    {
        $cacheKey = "github:latest_release:{$repository}";

        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            /** @var array{tag_name: string|null, name: string, html_url: string, published_at: string, body: string}|null */
            return json_decode($cached, true);
        }

        try {
            $response = $this->request('GET', "/repos/{$repository}/releases/latest");
            /** @var array<string, mixed> $data */
            $data = json_decode($response->getBody()->getContents(), true);

            $result = [
                'tag_name' => isset($data['tag_name']) ? (string) $data['tag_name'] : null,
                'name' => isset($data['name']) ? (string) $data['name'] : '',
                'html_url' => isset($data['html_url']) ? (string) $data['html_url'] : '',
                'published_at' => isset($data['published_at']) ? (string) $data['published_at'] : '',
                'body' => isset($data['body']) ? (string) $data['body'] : '',
            ];

            $this->cache->set($cacheKey, $this->cacheTtl, json_encode($result, JSON_THROW_ON_ERROR));

            return $result;
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_NOT_FOUND) {
                return null;
            }
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit hit for {$repository}");
                throw new RateLimitException($e->getResponse()->getHeaderLine('Retry-After'));
            }
            throw $e;
        }
    }

    private function request(string $method, string $uri): \Psr\Http\Message\ResponseInterface
    {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'GitHub-Release-Notifier/1.0',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        try {
            return $this->httpClient->request($method, self::API_BASE . $uri, [
                'headers' => $headers,
                'timeout' => 10,
            ]);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() === StatusCodeInterface::STATUS_TOO_MANY_REQUESTS) {
                $this->logger->warning("GitHub API rate limit exceeded");
                throw $e;
            }
            throw $e;
        }
    }
}
