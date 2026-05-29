<?php

declare(strict_types=1);

namespace Tests\Support;

use App\GitHub\GitHubApiClientInterface;

/**
 * Counting test double for the GitHub API boundary. Returns canned payloads
 * and records how many times each endpoint was hit, so caching use-case tests
 * can assert that a second lookup is served from the cache instead of the API.
 *
 * Mutable by design (call counters), hence not `readonly`.
 *
 * @psalm-api
 */
final class SpyGitHubApiClient implements GitHubApiClientInterface
{
    public int $getRepositoryCalls = 0;
    public int $getLatestReleaseCalls = 0;

    /**
     * @param array<string, mixed> $latestRelease
     * @param array<string, mixed> $repository
     */
    public function __construct(
        private readonly array $latestRelease = [],
        private readonly array $repository = []
    ) {
    }

    #[\Override]
    public function getRepository(string $repository): array
    {
        $this->getRepositoryCalls++;
        return $this->repository;
    }

    #[\Override]
    public function getLatestRelease(string $repository): array
    {
        $this->getLatestReleaseCalls++;
        return $this->latestRelease;
    }
}
