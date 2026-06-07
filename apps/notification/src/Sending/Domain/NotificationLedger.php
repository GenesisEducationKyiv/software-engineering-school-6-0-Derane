<?php

declare(strict_types=1);

namespace App\Sending\Domain;

interface NotificationLedger
{
    public function hasBeenSent(int $subscriptionId, string $tagName, string $repository): bool;
    public function markSent(int $subscriptionId, string $tagName, string $repository, string $email): void;
}
