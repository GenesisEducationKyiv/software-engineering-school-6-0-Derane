<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

/** @psalm-api */
final class SubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    #[\Override]
    public function create(string $email, string $repository): array
    {
        $this->ensureRepositoryExists($repository);

        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository) VALUES (:email, :repository)
             ON CONFLICT (email, repository) DO NOTHING
             RETURNING id, email, repository, created_at'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array{id: int, email: string, repository: string, created_at: string}|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result !== false) {
            return $result;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions '
            . 'WHERE email = :email AND repository = :repository'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array{id: int, email: string, repository: string, created_at: string}|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result === false) {
            throw new \RuntimeException("Subscription not found after insert for {$email}");
        }

        return $result;
    }

    /** @return array{id: int, email: string, repository: string, created_at: string}|null */
    #[\Override]
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, email, repository, created_at FROM subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array{id: int, email: string, repository: string, created_at: string}|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    #[\Override]
    public function findByEmail(string $email, int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions
             WHERE email = :email
             ORDER BY id
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('email', $email);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array{id: int, email: string, repository: string, created_at: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array{id: int, email: string, repository: string, created_at: string}> */
    #[\Override]
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions
             ORDER BY id
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array{id: int, email: string, repository: string, created_at: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function getActiveRepositories(): array
    {
        $stmt = $this->pdo->query('SELECT DISTINCT repository FROM subscriptions');
        if ($stmt === false) {
            return [];
        }
        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    #[\Override]
    public function getRepositoriesToScan(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT full_name
             FROM repositories
             ORDER BY last_checked_at ASC NULLS FIRST, full_name ASC
             LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    #[\Override]
    public function getSubscribersByRepository(string $repository): array
    {
        $stmt = $this->pdo->prepare('SELECT email FROM subscriptions WHERE repository = :repository');
        $stmt->execute(['repository' => $repository]);
        /** @var list<string> */
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    #[\Override]
    public function getSubscriptionsByRepository(string $repository): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email FROM subscriptions WHERE repository = :repository ORDER BY id'
        );
        $stmt->execute(['repository' => $repository]);
        /** @var list<array{id: int, email: string}> */
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    #[\Override]
    public function getRepositoryInfo(string $fullName): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM repositories WHERE full_name = :full_name');
        $stmt->execute(['full_name' => $fullName]);
        /** @var array<string, mixed>|false $result */
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result !== false ? $result : null;
    }

    #[\Override]
    public function hasSuccessfulNotificationForRelease(int $subscriptionId, string $repository, string $tag): bool
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
    public function recordNotificationResult(
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

    #[\Override]
    public function updateLastSeenTag(string $repository, string $tag): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_seen_tag = :tag, last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['tag' => $tag, 'repository' => $repository]);
    }

    #[\Override]
    public function updateLastChecked(string $repository): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['repository' => $repository]);
    }

    #[\Override]
    public function getMetrics(): array
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM subscriptions');
        $subscriptions = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM repositories');
        $repositories = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        $sql = "SELECT COUNT(*) FROM repositories WHERE last_seen_tag IS NOT NULL AND last_seen_tag != ''";
        $stmt = $this->pdo->query($sql);
        $withReleases = $stmt !== false ? (int) $stmt->fetchColumn() : 0;

        return [
            'subscriptions' => $subscriptions,
            'repositories' => $repositories,
            'repositories_with_releases' => $withReleases,
        ];
    }

    private function ensureRepositoryExists(string $repository): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT (full_name) DO NOTHING'
        );
        $stmt->execute(['full_name' => $repository]);
    }
}
