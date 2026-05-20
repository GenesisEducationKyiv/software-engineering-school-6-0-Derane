<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;
use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Repository\SubscriptionRepositoryInterface;
use App\Repository\TrackedRepositoryRegistrar;
use App\Validation\SubscriptionValidator;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        private SubscriptionRepositoryInterface $repository,
        private TrackedRepositoryRegistrar $trackedRepositories,
        private GitHubServiceInterface $gitHubService,
        private SubscriptionValidator $validator,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function subscribe(string $email, string $repoName): Subscription
    {
        $this->validator->assertValidSubscription($email, $repoName);

        if (!$this->gitHubService->repositoryExists($repoName)) {
            throw new RepositoryNotFoundException($repoName);
        }

        $this->trackedRepositories->ensureExists($repoName);
        $subscription = $this->repository->create($email, $repoName);

        $this->logger->info('Subscription created', [
            'email' => $email,
            'repository' => $repoName,
        ]);

        return $subscription;
    }

    #[\Override]
    public function unsubscribe(int $id): void
    {
        $this->findOrFail($id);
        $this->repository->delete($id);
        $this->logger->info('Subscription deleted', ['id' => $id]);
    }

    #[\Override]
    public function getSubscription(int $id): Subscription
    {
        return $this->findOrFail($id);
    }

    #[\Override]
    public function listSubscriptions(?string $email, Pagination $pagination): SubscriptionPage
    {
        if ($email !== null) {
            return $this->repository->findByEmail($email, $pagination);
        }

        return $this->repository->findAll($pagination);
    }

    private function findOrFail(int $id): Subscription
    {
        $subscription = $this->repository->findById($id);
        if ($subscription === null) {
            throw new SubscriptionNotFoundException($id);
        }

        return $subscription;
    }
}
