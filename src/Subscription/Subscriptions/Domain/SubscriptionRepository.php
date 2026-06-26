<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\ValueObject\Pagination;

/**
 * Persistence port for the Subscription aggregate. Implemented by the PDO adapter
 * in Infrastructure. Per-consumer ISP preserved: read/write of the subscription
 * row lives here; subscriber lookups for notification live on SubscriberFinder.
 *
 * @psalm-api
 */
interface SubscriptionRepository
{
    /**
     * Persists the aggregate and returns the reconstituted full row (incl. the
     * DB-assigned id + created_at). Idempotent on the (email, repository) unique
     * key: re-subscribing returns the existing row.
     */
    public function create(Subscription $subscription): Subscription;

    public function findById(int $id): ?Subscription;

    public function findByEmail(string $email, Pagination $pagination): SubscriptionPage;

    public function findAll(Pagination $pagination): SubscriptionPage;

    public function delete(int $id): bool;

    public function findByEmailAndRepository(string $email, string $repository): ?Subscription;
}
