<?php

declare(strict_types=1);

namespace App\Repository;

interface NotificationLedgerInterface
{
    public function hasSuccessfulNotification(int $subscriptionId, string $repository, string $tag): bool;

    public function recordResult(
        int $subscriptionId,
        string $repository,
        string $tag,
        bool $success,
        ?string $error = null
    ): void;
}
