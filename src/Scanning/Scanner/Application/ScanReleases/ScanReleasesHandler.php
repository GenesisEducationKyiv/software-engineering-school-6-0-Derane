<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application\ScanReleases;

use App\Notification\Publishing\Domain\NewReleaseDetected;
use App\Releases\Sourcing\Domain\RateLimitException;
use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\Scanning\Scanner\Application\ReleaseDetector;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\ValueObject\RepositoryName;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates a scan cycle: pulls due repositories, detects new releases, and
 * publishes SendReleaseEmail messages via the domain event dispatch.
 *
 * E1 Cutover: The legacy SMTP NotificationDispatcher is no longer invoked here.
 * Marker advancement (markReleaseSeen) is now gated by the success of the RabbitMQ
 * publish batch via the synchronous NewReleaseDetected event plane.
 *
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

        // AR-FLOW2: pre-commit synchronous dispatch, no outbox — this MUST run
        // before markReleaseSeen. Any exception here propagates untouched into
        // __invoke()'s per-repo catch: the marker stays un-advanced and the
        // release is re-detected next cycle.
        // Do NOT wrap this in a try/catch — that would silently defeat the
        // outbox-free guarantee (see InMemoryEventDispatcher's docblock).
        $this->eventDispatcher->dispatch(new NewReleaseDetected(
            new RepositoryName($repoName),
            $release,
            new \DateTimeImmutable()
        ));

        if ($release->tagName !== null) {
            $this->progress->markReleaseSeen($repoName, $release->tagName);
            return;
        }

        $this->progress->markChecked($repoName);
    }
}
