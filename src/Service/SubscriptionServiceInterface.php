<?php

declare(strict_types=1);

namespace App\Service;

interface SubscriptionServiceInterface
{
    /** @return array{id: int, email: string, repository: string, created_at: string} */
    public function subscribe(string $email, string $repoName): array;

    public function unsubscribe(int $id): void;

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    public function getSubscription(int $id): array;

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    public function listSubscriptions(?string $email = null, int $limit = 100, int $offset = 0): array;
}
