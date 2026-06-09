<?php

declare(strict_types=1);

namespace App\Service;

use App\Application\Event\Factory\ScanEventFactoryInterface;
use App\Exception\RateLimitException;
use App\Repository\ScanCandidateSource;
use App\Repository\ScanProgressWriter;
use App\Application\Event\EventPublisherInterface;

/** @psalm-api */
final readonly class ScannerService
{
    public function __construct(
        private ScanCandidateSource $candidates,
        private ScanProgressWriter $progress,
        private ReleaseDetector $detector,
        private NotificationDispatcherInterface $dispatcher,
        private EventPublisherInterface $events,
        private ScanEventFactoryInterface $eventFactory,
        private int $scanBatchSize = 100
    ) {
    }

    public function scan(): void
    {
        $start = microtime(true);
        $attempted = 0;

        try {
            $repositories = $this->candidates->getDueForScan($this->scanBatchSize);
            $this->events->publish($this->eventFactory->cycleStarted(count($repositories)));

            foreach ($repositories as $repoName) {
                $attempted++;
                try {
                    $this->checkRepository($repoName);
                } catch (RateLimitException $e) {
                    $this->events->publish($this->eventFactory->interruptedByRateLimit($repoName, $e->retryAfter));
                    break;
                } catch (\Exception $e) {
                    $this->events->publish($this->eventFactory->repositoryFailed($repoName, $e));
                }
            }
        } catch (\Throwable $e) {
            $this->events->publish($this->eventFactory->cycleFailed($e));
        } finally {
            // Count repositories actually attempted (a rate-limit break stops early,
            // so the batch size would overstate throughput) and always record the
            // cycle and its duration, even when it failed.
            $this->events->publish($this->eventFactory->cycleCompleted($attempted, microtime(true) - $start));
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
        $this->events->publish(
            $this->eventFactory->notificationBatchCompleted($repoName, $release->tagName, $allDelivered)
        );

        if ($allDelivered && $release->tagName !== null) {
            $this->progress->markReleaseSeen($repoName, $release->tagName);
            return;
        }

        $this->progress->markChecked($repoName);
        $this->events->publish($this->eventFactory->releaseMarkerWithheld($repoName, $release->tagName));
    }
}
