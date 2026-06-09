<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
final readonly class SmokeGitHubReleaseSource implements ReleaseSource
{
    /**
     * @param array{
     *     repository: string,
     *     tag_name: null|string,
     *     name: string,
     *     html_url: string,
     *     published_at: string,
     *     body: string
     * } $smokeRelease
     */
    public function __construct(private array $smokeRelease)
    {
    }

    #[\Override]
    public function repositoryExists(RepositoryName $repository): bool
    {
        return $repository->value() === $this->smokeRelease['repository'];
    }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        if ($repository->value() !== $this->smokeRelease['repository']) {
            return null;
        }

        return new Release(
            $this->smokeRelease['tag_name'],
            $this->smokeRelease['name'],
            $this->smokeRelease['html_url'],
            $this->smokeRelease['published_at'],
            $this->smokeRelease['body']
        );
    }
}
