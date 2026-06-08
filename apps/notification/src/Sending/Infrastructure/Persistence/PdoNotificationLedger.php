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
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo
               AND sent_at IS NOT NULL'
        );
        $stmt->execute([':sub' => $subscriptionId, ':tag' => $tagName, ':repo' => $repository]);
        return (int) ($stmt->fetchColumn() ?: 0) > 0;
    }

    #[\Override]
    public function markSent(int $subscriptionId, string $tagName, string $repository, string $email): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO release_notifications (subscription_id, tag_name, repository, email, sent_at, attempt_count)
             VALUES (:sub, :tag, :repo, :email, NOW(), 1)
             ON CONFLICT (subscription_id, tag_name, repository)
             DO UPDATE SET sent_at = NOW(), attempt_count = release_notifications.attempt_count + 1, last_error = NULL'
        );
        $stmt->execute([':sub' => $subscriptionId, ':tag' => $tagName, ':repo' => $repository, ':email' => $email]);
    }

    #[\Override]
    public function recordFailedAttempt(
        int $subscriptionId,
        string $tagName,
        string $repository,
        string $email,
        string $error,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO release_notifications (subscription_id, tag_name, repository, email, attempt_count, last_error)
             VALUES (:sub, :tag, :repo, :email, 1, :error)
             ON CONFLICT (subscription_id, tag_name, repository)
             DO UPDATE SET attempt_count = release_notifications.attempt_count + 1, last_error = :error'
        );
        $stmt->execute([
            ':sub'   => $subscriptionId,
            ':tag'   => $tagName,
            ':repo'  => $repository,
            ':email' => $email,
            ':error' => $error,
        ]);
    }
}
