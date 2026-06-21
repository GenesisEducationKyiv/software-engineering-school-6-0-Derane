<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * The only saga port the Subscription write path sees. start() runs INSIDE T1's
 * transaction (the atomic subscription+saga write) — an idempotent
 * `INSERT enrollment_sagas(...) ON CONFLICT (subscription_id) DO NOTHING`, so a
 * duplicate POST starts no second saga.
 *
 * The correlation key is the primitive int $subscriptionId (never a Subscription
 * value object) — the Saga.Domain <-> Subscription.Domain acyclicity invariant.
 *
 * @psalm-api
 */
interface EnrollmentSagaStarter
{
    public function start(int $subscriptionId): void;
}
