<?php

declare(strict_types=1);

namespace App\Service;

interface SubscriptionServiceInterface
{
    public function subscribe(string $email, string $repoName): array;

    public function unsubscribe(int $id): void;

    public function getSubscription(int $id): array;

    public function listSubscriptions(?string $email = null, int $limit = 100, int $offset = 0): array;
}
