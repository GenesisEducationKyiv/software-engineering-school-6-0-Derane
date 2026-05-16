<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deterministic in-process replacement for GitHubService used in test stacks
 * (Behat acceptance + Playwright E2E + PHPUnit Integration). Enabled by
 * setting the GITHUB_STUB env var to a truthy value.
 *
 * Reports every repository as existing, except names beginning with
 * "nonexistent" which mimic GitHub's 404 path so 404 scenarios stay covered.
 *
 * @psalm-api
 */
final class FakeGitHubService implements GitHubServiceInterface
{
    #[\Override]
    public function repositoryExists(string $repository): bool
    {
        return !str_starts_with(strtolower($repository), 'nonexistent');
    }

    #[\Override]
    public function getLatestRelease(string $repository): ?array
    {
        return null;
    }
}
