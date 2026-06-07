<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;

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
    public function repositoryExists(string $repository): bool
    {
        return $repository === $this->smokeRelease['repository'];
    }

    #[\Override]
    public function getLatestRelease(string $repository): ?Release
    {
        if ($repository !== $this->smokeRelease['repository']) {
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
