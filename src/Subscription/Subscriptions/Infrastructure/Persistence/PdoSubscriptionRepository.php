<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Shared\Domain\ValueObject\Pagination;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriberCollection;
use App\Subscription\Subscriptions\Domain\SubscriberFinder;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionCountPort;
use App\Subscription\Subscriptions\Domain\SubscriptionPage;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriberRefFactoryInterface;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use PDO;

/** @psalm-api */
final readonly class PdoSubscriptionRepository implements
    SubscriptionRepository,
    SubscriberFinder,
    SubscriptionCountPort
{
    public function __construct(
        private PDO $pdo,
        private SubscriptionFactoryInterface $subscriptionFactory,
        private SubscriberRefFactoryInterface $subscriberRefFactory
    ) {
    }

    #[\Override]
    public function create(Subscription $subscription): Subscription
    {
        $email = $subscription->email();
        $repository = $subscription->repository();

        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository) VALUES (:email, :repository)
             ON CONFLICT (email, repository) DO NOTHING
             RETURNING id, email, repository, created_at, status'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->subscriptionFactory->reconstitute($row);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at, status FROM subscriptions '
            . 'WHERE email = :email AND repository = :repository'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Subscription not found after insert for {$email}");
        }

        return $this->subscriptionFactory->reconstitute($row);
    }

    #[\Override]
    public function findById(int $id): ?Subscription
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at, status FROM subscriptions WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->subscriptionFactory->reconstitute($row) : null;
    }

    #[\Override]
    public function findByEmailAndRepository(string $email, string $repository): ?Subscription
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at, status FROM subscriptions '
            . 'WHERE email = :email AND repository = :repository'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->subscriptionFactory->reconstitute($row) : null;
    }

    #[\Override]
    public function findByEmail(string $email, Pagination $pagination): SubscriptionPage
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at, status FROM subscriptions
             WHERE email = :email
             ORDER BY id
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('email', $email);
        $stmt->bindValue('limit', $pagination->limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $pagination->offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE email = :email');
        $count->execute(['email' => $email]);
        $total = (int) $count->fetchColumn();

        return new SubscriptionPage($this->mapSubscriptions($rows), $pagination, $total);
    }

    #[\Override]
    public function findAll(Pagination $pagination): SubscriptionPage
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at, status FROM subscriptions
             ORDER BY id
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $pagination->limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $pagination->offset, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM subscriptions');
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        return new SubscriptionPage($this->mapSubscriptions($rows), $pagination, $total);
    }

    #[\Override]
    public function delete(int $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    #[\Override]
    public function findSubscribersByRepository(RepositoryName $repository): SubscriberCollection
    {
        // Recipient resolution (FR14 / AC6a): release emails go ONLY to CONFIRMED
        // subscribers. This is the ONE filtered SELECT — read/list projections stay
        // unfiltered so owners still see their own pending/cancelled rows. Served by
        // idx_subscriptions_repository_status (migration 004).
        $stmt = $this->pdo->prepare(
            "SELECT id, email FROM subscriptions
             WHERE repository = :repository AND status = 'confirmed'
             ORDER BY id"
        );
        $stmt->execute(['repository' => $repository->value()]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return new SubscriberCollection(array_map(
            fn(array $row) => $this->subscriberRefFactory->fromRow($row),
            $rows
        ));
    }

    #[\Override]
    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM subscriptions');
        return $stmt !== false ? (int) $stmt->fetchColumn() : 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Subscription>
     */
    private function mapSubscriptions(array $rows): array
    {
        return array_map(
            fn(array $row) => $this->subscriptionFactory->reconstitute($row),
            $rows
        );
    }
}
