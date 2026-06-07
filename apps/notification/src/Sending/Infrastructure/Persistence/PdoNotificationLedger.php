<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Persistence;

use App\Sending\Domain\NotificationLedger;

final readonly class PdoNotificationLedger implements NotificationLedger
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function hasBeenSent(int $subscriptionId, string $tagName, string $repository): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([':sub' => $subscriptionId, ':tag' => $tagName, ':repo' => $repository]);
        return (int) ($stmt->fetchColumn() ?: 0) > 0;
    }

    #[\Override]
    public function markSent(int $subscriptionId, string $tagName, string $repository, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO release_notifications (subscription_id, tag_name, repository, email)
             VALUES (:sub, :tag, :repo, :email)
             ON CONFLICT (subscription_id, tag_name, repository) DO NOTHING'
        );
        $stmt->execute([':sub' => $subscriptionId, ':tag' => $tagName, ':repo' => $repository, ':email' => $email]);
    }
}
