<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

/**
 * Cross-context Domain port consumed by the saga orchestrator (the granted
 * Saga.Application -> Subscription.Domain edge). Each method is a state-guarded
 * conditional `UPDATE subscriptions SET status = :new WHERE id = :id AND
 * status = 'pending'` returning `rowCount() > 0`, so a replayed reply is a no-op
 * and a terminal status is never resurrected.
 *
 * The PDO adapter lands in Subscription.Infrastructure (Epic B / B3); this is the
 * interface only.
 *
 * @psalm-api
 */
interface SubscriptionConfirmationWriter
{
    /** pending -> confirmed. Returns false (no-op) if the row is not pending. */
    public function confirm(int $id): bool;

    /** pending -> cancelled. Returns false (no-op) if the row is not pending. */
    public function cancel(int $id): bool;
}
