<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RateLimitException;
use App\Observability\Metrics\ScanMetrics;
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
        private ScanMetrics $metrics,
        private int $scanBatchSize = 100
    ) {
    }

    public function scan(): void
    {
        $start = microtime(true);
        $attempted = 0;

        try {
            $repositories = $this->candidates->getDueForScan($this->scanBatchSize);
            $this->logger->info('Scanning ' . count($repositories) . ' repositories for new releases');

            foreach ($repositories as $repoName) {
                $attempted++;
                try {
                    $this->checkRepository($repoName);
                } catch (RateLimitException $e) {
                    $this->metrics->errorOccurred('rate_limit');
                    $this->logger->warning('Rate limited — stopping scan cycle early', [
                        'repository' => $repoName,
                        'retry_after' => $e->retryAfter,
                    ]);
                    break;
                } catch (\Exception $e) {
                    $this->metrics->errorOccurred('error');
                    $this->logger->error('Scan error', [
                        'repository' => $repoName,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            // Cycle-level failure (e.g. the candidate query itself failed).
            $this->metrics->errorOccurred('cycle');
            $this->logger->error('Scan cycle failed', ['error' => $e->getMessage()]);
        } finally {
            // Count repositories actually attempted (a rate-limit break stops early,
            // so the batch size would overstate throughput) and always record the
            // cycle and its duration, even when it failed.
            $this->metrics->cycleCompleted($attempted, microtime(true) - $start);
        }
    }

    private function checkRepository(string $repoName): void
    {
        $release = $this->detector->detect($repoName);
        if ($release === null) {
            $this->progress->markChecked($repoName);
            return;
        }

        $this->metrics->releaseDetected();

        $allDelivered = $this->dispatcher->dispatch($repoName, $release);
        $this->metrics->notification($allDelivered ? 'sent' : 'failed');

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
