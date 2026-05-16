<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;
use App\Repository\RepositoryStatusReader;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class ReleaseDetector
{
    public function __construct(
        private GitHubServiceInterface $gitHubService,
        private RepositoryStatusReader $trackedRepositories,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Returns the latest release if it differs from the last seen tag for the
     * given repository, otherwise null.
     */
    public function detect(string $repoName): ?Release
    {
        $release = $this->gitHubService->getLatestRelease($repoName);
        if ($release === null || $release->tagName === null) {
            return null;
        }

        $status = $this->trackedRepositories->getStatus($repoName);
        $lastSeenTag = $status?->lastSeenTag;

        if ($lastSeenTag === $release->tagName) {
            return null;
        }

        $this->logger->info('New release found', [
            'repository' => $repoName,
            'tag' => $release->tagName,
            'previous_tag' => $lastSeenTag,
        ]);

        return $release;
    }
}
