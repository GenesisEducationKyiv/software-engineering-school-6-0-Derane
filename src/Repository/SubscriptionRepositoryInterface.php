<?php

declare(strict_types=1);

namespace App\Repository;

interface SubscriptionRepositoryInterface
{
    public function create(string $email, string $repository): array;

    public function findById(int $id): ?array;

    public function findByEmail(string $email, int $limit = 100, int $offset = 0): array;

    public function delete(int $id): bool;

    public function findAll(int $limit = 100, int $offset = 0): array;

    public function getActiveRepositories(): array;

    public function getRepositoriesToScan(int $limit): array;

    public function getSubscribersByRepository(string $repository): array;

    public function getSubscriptionsByRepository(string $repository): array;

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

    public function getMetrics(): array;
}
