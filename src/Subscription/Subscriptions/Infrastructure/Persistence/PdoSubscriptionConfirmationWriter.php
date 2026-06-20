<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use PDO;

/**
 * PDO adapter for the SubscriptionConfirmationWriter port (the granted
 * Saga.Application -> Subscription.Domain edge). Each method is a state-guarded
 * conditional UPDATE on the shared PDO::class:
 *
 *   UPDATE subscriptions SET status = :new WHERE id = :id AND status = 'pending'
 *
 * returning rowCount() > 0. The `status = 'pending'` guard is the saga's true
 * single-writer lock: a replayed reply is a no-op (rowCount() = 0) and a terminal
 * status (confirmed/cancelled) is never resurrected.
 *
 * @psalm-api
 */
final readonly class PdoSubscriptionConfirmationWriter implements SubscriptionConfirmationWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function confirm(int $id): bool
    {
        return $this->transition($id, 'confirmed');
    }

    #[\Override]
    public function cancel(int $id): bool
    {
        return $this->transition($id, 'cancelled');
    }

    private function transition(int $id, string $newStatus): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE subscriptions SET status = :new
             WHERE id = :id AND status = \'pending\''
        );
        $stmt->execute([':new' => $newStatus, ':id' => $id]);

        return $stmt->rowCount() > 0;
    }
}
