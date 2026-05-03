<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RepositoryNotFoundException;
use App\Exception\SubscriptionNotFoundException;
use App\Exception\ValidationException;
use App\Repository\SubscriptionRepositoryInterface;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final class SubscriptionService implements SubscriptionServiceInterface
{
    public function __construct(
        private SubscriptionRepositoryInterface $repository,
        private GitHubServiceInterface $gitHubService,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function subscribe(string $email, string $repoName): array
    {
        $this->validateEmail($email);
        $this->validateRepositoryFormat($repoName);

        if (!$this->gitHubService->repositoryExists($repoName)) {
            throw new RepositoryNotFoundException($repoName);
        }

        $subscription = $this->repository->create($email, $repoName);
        $this->logger->info("Subscription created", ['email' => $email, 'repository' => $repoName]);

        return $subscription;
    }

    #[\Override]
    public function unsubscribe(int $id): void
    {
        $this->findOrFail($id);
        $this->repository->delete($id);
        $this->logger->info("Subscription deleted", ['id' => $id]);
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    #[\Override]
    public function getSubscription(int $id): array
    {
        return $this->findOrFail($id);
    }

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    #[\Override]
    public function listSubscriptions(?string $email = null, int $limit = 100, int $offset = 0): array
    {
        if ($email !== null) {
            return $this->repository->findByEmail($email, $limit, $offset);
        }

        return $this->repository->findAll($limit, $offset);
    }

    public static function isValidRepositoryFormat(string $repository): bool
    {
        return (bool) preg_match('/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/', $repository);
    }

    public static function isValidEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    private function findOrFail(int $id): array
    {
        $subscription = $this->repository->findById($id);
        if ($subscription === null) {
            throw new SubscriptionNotFoundException($id);
        }
        return $subscription;
    }

    private function validateEmail(string $email): void
    {
        if (!self::isValidEmail($email)) {
            throw new ValidationException("Invalid email format");
        }
    }

    private function validateRepositoryFormat(string $repository): void
    {
        if (!self::isValidRepositoryFormat($repository)) {
            throw new ValidationException("Invalid repository format. Expected: owner/repo");
        }
    }
}
