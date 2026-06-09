<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application\ScanReleases;

use App\Releases\Sourcing\Domain\NewReleaseDetected;
use App\Releases\Sourcing\Domain\RateLimitException;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Scanning\Scanner\Application\ReleaseDetector;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Clock;
use App\Shared\Domain\ValueObject\RepositoryName;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * @implements CommandHandler<ScanReleasesCommand>
 * @psalm-api
 */
final readonly class ScanReleasesHandler implements CommandHandler
{
    public function __construct(
        private ScanCandidateSource $candidates,
        private ScanProgressWriter $progress,
        private ReleaseDetector $detector,
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock,
        private LoggerInterface $logger,
        private int $scanBatchSize = 100
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $repositories = $this->candidates->getDueForScan($this->scanBatchSize);
        $this->logger->info('Scanning ' . count($repositories) . ' repositories for new releases');

        foreach ($repositories as $repoName) {
            try {
                $this->checkRepository(new RepositoryName($repoName));
            } catch (RateLimitException $e) {
                $this->logger->warning('Rate limited — stopping scan cycle early', [
                    'repository' => $repoName,
                    'retry_after' => $e->retryAfter,
                ]);
                break;
            } catch (\Exception $e) {
                $this->logger->error('Scan error', [
                    'repository' => $repoName,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function checkRepository(RepositoryName $repository): void
    {
        $detected = $this->detector->detect($repository);
        if ($detected === null) {
            $this->progress->markChecked($repository->value());
            return;
        }

        // Must run before markReleaseSeen. Exceptions propagate to the per-repo
        // catch so the marker stays un-advanced. Do NOT wrap in try/catch.
        $this->eventDispatcher->dispatch(new NewReleaseDetected(
            $repository,
            $detected,
            $this->clock->now()
        ));

        $this->progress->markReleaseSeen($repository->value(), $detected->tag->value());
    }
}
