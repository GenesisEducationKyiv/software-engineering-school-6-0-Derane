<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Persistence;

use App\Sending\Domain\ClaimResult;
use App\Sending\Domain\NotificationKey;
use App\Sending\Domain\NotificationLedger;

/**
 * Ledger states are encoded in two nullable timestamps:
 * - sent_at NOT NULL                          → sent
 * - sent_at NULL, claimed_at NOT NULL         → in flight (claimed)
 * - sent_at NULL, claimed_at NULL             → failed / never attempted (claimable)
 *
 * claim() is a single INSERT … ON CONFLICT DO UPDATE … WHERE: the row lock
 * taken by the upsert makes it atomic — exactly one of two racing workers
 * gets a row back. The conditional update only fires for claimable rows, so
 * sent and freshly claimed rows return nothing and the loser backs off.
 *
 * Every won claim stores a fresh claim_token; markSent/recordFailedAttempt
 * require that token in their WHERE clause, so a worker whose lease expired
 * and was re-claimed cannot overwrite the new holder's state.
 */
final readonly class PdoNotificationLedger implements NotificationLedger
{
    public function __construct(private \PDO $pdo)
    {
    }

    #[\Override]
    public function claim(NotificationKey $key, string $email): ClaimResult
    {
        $token = bin2hex(random_bytes(16));

        $stmt = $this->pdo->prepare(sprintf(
            'INSERT INTO release_notifications
                 (subscription_id, tag_name, repository, email, claimed_at, claim_token, attempt_count)
             VALUES (:sub, :tag, :repo, :email, NOW(), :token, 0)
             ON CONFLICT (subscription_id, repository, tag_name)
             DO UPDATE SET claimed_at = NOW(), claim_token = excluded.claim_token, email = excluded.email,
                           updated_at = NOW()
             WHERE release_notifications.sent_at IS NULL
               AND (release_notifications.claimed_at IS NULL
                    OR release_notifications.claimed_at < NOW() - INTERVAL \'%d seconds\')
             RETURNING id',
            NotificationLedger::CLAIM_LEASE_SECONDS,
        ));
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName,
            ':repo' => $key->repository,
            ':email' => $email,
            ':token' => $token,
        ]);

        if ($stmt->fetchColumn() !== false) {
            return ClaimResult::claimed($token);
        }

        return $this->hasBeenSent($key) ? ClaimResult::alreadySent() : ClaimResult::inFlight();
    }

    #[\Override]
    public function markSent(NotificationKey $key, string $email, string $claimToken): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE release_notifications
             SET sent_at = NOW(), claimed_at = NULL, claim_token = NULL, email = :email,
                 attempt_count = attempt_count + 1, last_error = NULL,
                 updated_at = NOW()
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo
               AND claim_token = :token'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName,
            ':repo' => $key->repository,
            ':email' => $email,
            ':token' => $claimToken,
        ]);
    }

    #[\Override]
    public function recordFailedAttempt(NotificationKey $key, string $email, string $error, string $claimToken): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE release_notifications
             SET claimed_at = NULL, claim_token = NULL, email = :email,
                 attempt_count = attempt_count + 1, last_error = :error,
                 updated_at = NOW()
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo
               AND claim_token = :token'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName,
            ':repo' => $key->repository,
            ':email' => $email,
            ':error' => $error,
            ':token' => $claimToken,
        ]);
    }

    private function hasBeenSent(NotificationKey $key): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo
               AND sent_at IS NOT NULL'
        );
        $stmt->execute([
            ':sub' => $key->subscriptionId,
            ':tag' => $key->tagName,
            ':repo' => $key->repository,
        ]);

        return (int) ($stmt->fetchColumn() ?: 0) > 0;
    }
}
