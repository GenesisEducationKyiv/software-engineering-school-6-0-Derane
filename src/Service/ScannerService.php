<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RateLimitException;
use App\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class ScannerService
{
    public function __construct(
        private SubscriptionRepositoryInterface $repository,
        private GitHubServiceInterface $gitHubService,
        private NotifierInterface $notifierService,
        private LoggerInterface $logger,
        private int $scanBatchSize = 100
    ) {
    }

    public function scan(): void
    {
        $repositories = $this->repository->getRepositoriesToScan($this->scanBatchSize);
        $this->logger->info("Scanning " . count($repositories) . " repositories for new releases");

        foreach ($repositories as $repoName) {
            if (!$this->checkRepository($repoName)) {
                $this->logger->warning("Rate limited — stopping scan cycle early");
                break;
            }
        }
    }

    /**
     * @return bool true if scan can continue, false if rate-limited
     */
    public function checkRepository(string $repoName): bool
    {
        try {
            $release = $this->gitHubService->getLatestRelease($repoName);

            if ($release === null || $release['tag_name'] === null) {
                $this->repository->updateLastChecked($repoName);
                return true;
            }

            $tagName = $release['tag_name'];

            $repoInfo = $this->repository->getRepositoryInfo($repoName);
            /** @var string|null $lastSeenTag */
            $lastSeenTag = $repoInfo !== null ? ($repoInfo['last_seen_tag'] ?? null) : null;

            if ($lastSeenTag === $tagName) {
                $this->repository->updateLastChecked($repoName);
                return true;
            }

            $this->logger->info("New release found", [
                'repository' => $repoName,
                'tag' => $tagName,
                'previous_tag' => $lastSeenTag,
            ]);

            $subscribers = $this->repository->getSubscriptionsByRepository($repoName);
            $allDelivered = true;

            foreach ($subscribers as $subscription) {
                $subscriptionId = $subscription['id'];
                $email = $subscription['email'];

                if (
                    $this->repository->hasSuccessfulNotificationForRelease(
                        $subscriptionId,
                        $repoName,
                        $tagName
                    )
                ) {
                    continue;
                }

                $sent = $this->notifierService->sendReleaseNotification(
                    $email,
                    $repoName,
                    $tagName,
                    $release['name'],
                    $release['html_url'],
                    $release['body']
                );
                $this->repository->recordNotificationResult(
                    $subscriptionId,
                    $repoName,
                    $tagName,
                    $sent,
                    $sent ? null : 'Failed to send notification'
                );
                if (!$sent) {
                    $allDelivered = false;
                }
            }

            if ($allDelivered) {
                $this->repository->updateLastSeenTag($repoName, $tagName);
            } else {
                $this->repository->updateLastChecked($repoName);
                $this->logger->warning("Some notifications failed; release marker not advanced", [
                    'repository' => $repoName,
                    'tag' => $tagName,
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
