<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application;

use App\Releases\Sourcing\Domain\DetectedRelease;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\RepositoryStatusReader;
use App\Shared\Domain\ValueObject\ReleaseTag;
use App\Shared\Domain\ValueObject\RepositoryName;
use Psr\Log\LoggerInterface;

/**
 * The tagName null-check below is the single boundary guard for tagless
 * GitHub releases: everything downstream consumes DetectedRelease, whose
 * ReleaseTag is non-nullable by construction.
 *
 * @psalm-api
 */
final readonly class ReleaseDetector
{
    public function __construct(
        private ReleaseSource $gitHubService,
        private RepositoryStatusReader $trackedRepositories,
        private LoggerInterface $logger
    ) {
    }

    public function detect(RepositoryName $repository): ?DetectedRelease
    {
        $release = $this->gitHubService->getLatestRelease($repository);
        if ($release === null || $release->tagName === null) {
            return null;
        }

        $status = $this->trackedRepositories->getStatus($repository->value());
        $lastSeenTag = $status?->lastSeenTag();

        if ($lastSeenTag === $release->tagName) {
            return null;
        }

        $this->logger->info('New release found', [
            'repository' => $repository->value(),
            'tag' => $release->tagName,
            'previous_tag' => $lastSeenTag,
        ]);

        return new DetectedRelease(new ReleaseTag($release->tagName), $release);
    }
}
