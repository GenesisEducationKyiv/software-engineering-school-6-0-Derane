<?php

declare(strict_types=1);

namespace App\Repository;

use App\Config\Pagination;
use App\Domain\Factory\SubscriberRefFactoryInterface;
use App\Domain\Factory\SubscriptionFactoryInterface;
use App\Domain\SubscriberCollection;
use App\Domain\Subscription;
use App\Domain\SubscriptionPage;
use PDO;

/** @psalm-api */
final class SubscriptionRepository implements SubscriptionRepositoryInterface, SubscriberFinderInterface
{
    public function __construct(
        private PDO $pdo,
        private SubscriptionFactoryInterface $subscriptionFactory,
        private SubscriberRefFactoryInterface $subscriberRefFactory
    ) {
    }

    #[\Override]
    public function create(string $email, string $repository): Subscription
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository) VALUES (:email, :repository)
             ON CONFLICT (email, repository) DO NOTHING
             RETURNING id, email, repository, created_at'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->subscriptionFactory->fromRow($row);
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions '
            . 'WHERE email = :email AND repository = :repository'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new \RuntimeException("Subscription not found after insert for {$email}");
        }

        return $this->subscriptionFactory->fromRow($row);
    }

    #[\Override]
    public function findById(int $id): ?Subscription
    {
        $stmt = $this->pdo->prepare('SELECT id, email, repository, created_at FROM subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->subscriptionFactory->fromRow($row) : null;
    }

    #[\Override]
    public function findByEmail(string $email, Pagination $pagination): SubscriptionPage
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions
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
            'SELECT id, email, repository, created_at FROM subscriptions
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
    public function findSubscribersByRepository(string $repository): SubscriberCollection
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email FROM subscriptions WHERE repository = :repository ORDER BY id'
        );
        $stmt->execute(['repository' => $repository]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return new SubscriberCollection(array_map(
            fn(array $row) => $this->subscriberRefFactory->fromRow($row),
            $rows
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<Subscription>
     */
    private function mapSubscriptions(array $rows): array
    {
        return array_map(
            fn(array $row) => $this->subscriptionFactory->fromRow($row),
            $rows
        );
    }
}
