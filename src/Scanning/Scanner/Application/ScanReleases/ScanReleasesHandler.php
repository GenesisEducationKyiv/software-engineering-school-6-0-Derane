<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application\ScanReleases;

use App\Releases\Sourcing\Domain\RateLimitException;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Service\NotificationDispatcherInterface;
use App\Service\ReleaseDetector;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
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
        private ReleaseDetector $detector,                    // transitional: moves to Scanning in B5
        private NotificationDispatcherInterface $dispatcher,  // transitional: moves to Scanning in B5
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
                $this->checkRepository($repoName);
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

    private function checkRepository(string $repoName): void
    {
        $release = $this->detector->detect($repoName);
        if ($release === null) {
            $this->progress->markChecked($repoName);
            return;
        }

        $allDelivered = $this->dispatcher->dispatch($repoName, $release);

        if ($allDelivered && $release->tagName !== null) {
            $this->progress->markReleaseSeen($repoName, $release->tagName);
            return;
        }

        $this->progress->markChecked($repoName);
        $this->logger->warning('Some notifications failed; release marker not advanced', [
            'repository' => $repoName,
            'tag' => $release->tagName,
        ]);
    }
}
