<?php

declare(strict_types=1);

namespace App\GitHub;

use GuzzleHttp\ClientInterface;

/** @psalm-api */
final readonly class GitHubApiClient implements GitHubApiClientInterface
{
    private const API_BASE = 'https://api.github.com';

    public function __construct(
        private ClientInterface $httpClient,
        private string $token = ''
    ) {
    }

    #[\Override]
    public function getRepository(string $repository): array
    {
        return $this->getJson("/repos/{$repository}");
    }

    #[\Override]
    public function getLatestRelease(string $repository): array
    {
        return $this->getJson("/repos/{$repository}/releases/latest");
    }

    /** @return array<string, mixed> */
    private function getJson(string $uri): array
    {
        $response = $this->httpClient->request('GET', self::API_BASE . $uri, [
            'headers' => $this->headers(),
            'timeout' => 10,
        ]);

        $payload = json_decode($response->getBody()->getContents(), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($payload)) {
            throw new \RuntimeException('GitHub API returned a non-object JSON payload');
        }

        if ($payload !== [] && array_is_list($payload)) {
            throw new \RuntimeException('GitHub API returned a list JSON payload');
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        $headers = [
            'Accept' => 'application/vnd.github.v3+json',
            'User-Agent' => 'GitHub-Release-Notifier/1.0',
        ];

        if ($this->token !== '') {
            $headers['Authorization'] = "Bearer {$this->token}";
        }

        return $headers;
    }
}
