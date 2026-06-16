<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
final readonly class SmokeGitHubReleaseSource implements ReleaseSource
{
    public function __construct(
        private RepositoryName $repository,
        private Release $release,
    ) {
    }

    #[\Override]
    public function repositoryExists(RepositoryName $repository): bool
    {
        return $repository->equals($this->repository);
    }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        return $repository->equals($this->repository) ? $this->release : null;
    }
}
