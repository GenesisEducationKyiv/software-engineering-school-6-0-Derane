<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** @psalm-api */
final readonly class NotificationLedger implements NotificationLedgerInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function hasSuccessfulNotification(int $subscriptionId, string $repository, string $tag): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
             FROM release_notifications
             WHERE subscription_id = :subscription_id
               AND repository = :repository
               AND tag_name = :tag_name
               AND sent_at IS NOT NULL'
        );
        $stmt->execute([
            'subscription_id' => $subscriptionId,
            'repository' => $repository,
            'tag_name' => $tag,
        ]);

        return $stmt->fetchColumn() !== false;
    }

    #[\Override]
    public function recordResult(
        int $subscriptionId,
        string $repository,
        string $tag,
        bool $success,
        ?string $error = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO release_notifications (
                subscription_id,
                repository,
                tag_name,
                sent_at,
                attempts,
                last_error,
                updated_at
             ) VALUES (
                :subscription_id,
                :repository,
                :tag_name,
                :sent_at,
                1,
                :last_error,
                NOW()
             )
             ON CONFLICT (subscription_id, repository, tag_name)
             DO UPDATE SET
                sent_at = CASE
                    WHEN EXCLUDED.sent_at IS NOT NULL THEN EXCLUDED.sent_at
                    ELSE release_notifications.sent_at
                END,
                attempts = release_notifications.attempts + 1,
                last_error = EXCLUDED.last_error,
                updated_at = NOW()'
        );
        $stmt->execute([
            'subscription_id' => $subscriptionId,
            'repository' => $repository,
            'tag_name' => $tag,
            'sent_at' => $success ? date('c') : null,
            'last_error' => $success ? null : $error,
        ]);
    }
}
