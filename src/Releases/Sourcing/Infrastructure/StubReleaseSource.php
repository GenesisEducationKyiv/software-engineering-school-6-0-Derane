<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Deterministic in-process replacement for GitHubApiReleaseSource used by test
 * stacks (Behat acceptance + Playwright E2E + PHPUnit Integration). Enabled by
 * setting the GITHUB_STUB env var to a truthy value.
 *
 * Lives in Infrastructure (not tests/) because config/container.php wires it:
 * the composition root must never depend on autoload-dev code — a
 * `composer install --no-dev` deployment with GITHUB_STUB set would fatal.
 *
 * Reports every repository as existing, except names beginning with
 * "nonexistent" which mimic GitHub's 404 path so 404 scenarios stay covered.
 *
 * @psalm-api
 */
final readonly class StubReleaseSource implements ReleaseSource
{
    #[\Override]
    public function repositoryExists(RepositoryName $repository): bool
    {
        return !str_starts_with(strtolower($repository->value()), 'nonexistent');
    }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        return null;
    }
}
