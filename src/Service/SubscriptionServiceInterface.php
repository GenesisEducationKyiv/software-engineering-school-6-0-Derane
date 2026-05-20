<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\Pagination;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;

interface SubscriptionServiceInterface
{
    public function subscribe(string $email, string $repoName): Subscription;

    public function unsubscribe(int $id): void;

    public function getSubscription(int $id): Subscription;

    public function listSubscriptions(?string $email, Pagination $pagination): SubscriptionPage;
}
