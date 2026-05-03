<?php

declare(strict_types=1);

namespace App\Repository;

/** @psalm-api */
interface SubscriptionRepositoryInterface
{
    /** @return array{id: int, email: string, repository: string, created_at: string} */
    public function create(string $email, string $repository): array;

    /** @return array{id: int, email: string, repository: string, created_at: string}|null */
    public function findById(int $id): ?array;

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    public function findByEmail(string $email, int $limit = 100, int $offset = 0): array;

    public function delete(int $id): bool;

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    public function findAll(int $limit = 100, int $offset = 0): array;

    /** @return list<string> */
    public function getActiveRepositories(): array;

    /** @return list<string> */
    public function getRepositoriesToScan(int $limit): array;

    /** @return list<string> */
    public function getSubscribersByRepository(string $repository): array;

    /** @return list<array{id: int, email: string}> */
    public function getSubscriptionsByRepository(string $repository): array;

    /** @return array<string, mixed>|null */
    public function getRepositoryInfo(string $fullName): ?array;

    public function hasSuccessfulNotificationForRelease(int $subscriptionId, string $repository, string $tag): bool;

    public function recordNotificationResult(
        int $subscriptionId,
        string $repository,
        string $tag,
        bool $success,
        ?string $error = null
    ): void;

    public function updateLastSeenTag(string $repository, string $tag): void;

    public function updateLastChecked(string $repository): void;

    /** @return array{subscriptions: int, repositories: int, repositories_with_releases: int} */
    public function getMetrics(): array;
}
