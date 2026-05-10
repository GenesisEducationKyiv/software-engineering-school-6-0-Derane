<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RateLimitException;
use App\Repository\TrackedRepositoryRepositoryInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class ScannerService
{
    public function __construct(
        private TrackedRepositoryRepositoryInterface $trackedRepositories,
        private ReleaseDetector $detector,
        private NotificationDispatcher $dispatcher,
        private LoggerInterface $logger,
        private int $scanBatchSize = 100
    ) {
    }

    public function scan(): void
    {
        $repositories = $this->trackedRepositories->getDueForScan($this->scanBatchSize);
        $this->logger->info('Scanning ' . count($repositories) . ' repositories for new releases');

        foreach ($repositories as $repoName) {
            if (!$this->checkRepository($repoName)) {
                $this->logger->warning('Rate limited — stopping scan cycle early');
                break;
            }
        }
    }

    /** @return bool true if scan can continue, false if rate-limited */
    public function checkRepository(string $repoName): bool
    {
        try {
            $release = $this->detector->detect($repoName);
            if ($release === null) {
                $this->trackedRepositories->markChecked($repoName);
                return true;
            }

            $allDelivered = $this->dispatcher->dispatch($repoName, $release);

            if ($allDelivered && $release->tagName !== null) {
                $this->trackedRepositories->markReleaseSeen($repoName, $release->tagName);
            } else {
                $this->trackedRepositories->markChecked($repoName);
                $this->logger->warning('Some notifications failed; release marker not advanced', [
                    'repository' => $repoName,
                    'tag' => $release->tagName,
                ]);
            }
        } catch (RateLimitException $e) {
            $this->logger->warning("Rate limited for {$repoName}: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Error checking repository {$repoName}: " . $e->getMessage());
        }

        return true;
    }
}
