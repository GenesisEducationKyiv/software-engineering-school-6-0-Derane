<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RateLimitException;
use App\Repository\ScanCandidateSource;
use App\Repository\ScanProgressWriter;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class ScannerService
{
    public function __construct(
        private ScanCandidateSource $candidates,
        private ScanProgressWriter $progress,
        private ReleaseDetector $detector,
        private NotificationDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
        private int $scanBatchSize = 100
    ) {
    }

    public function scan(): void
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
            } catch (\Throwable $e) {
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
