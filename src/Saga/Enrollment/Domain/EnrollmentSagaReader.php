<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Read side of the saga store (per-consumer ISP). Feeds the relay and the
 * timeout sweeper.
 *
 * @psalm-api
 */
interface EnrollmentSagaReader
{
    /**
     * Sagas in state Started awaiting their first (or retried) relay publish,
     * oldest first.
     *
     * @return iterable<EnrollmentSaga>
     */
    public function dueForRelay(int $limit): iterable;

    /**
     * The PRIMARY timeout sweep: AwaitingConfirmation sagas whose awaiting_since
     * is older than $now - $timeoutSeconds. Binds the passed $now, not SQL NOW(),
     * for deterministic sweeping.
     *
     * @return iterable<EnrollmentSaga>
     */
    public function dueForSweep(\DateTimeImmutable $now, int $timeoutSeconds): iterable;

    /**
     * The SECONDARY start sweep (broker-down backstop): Started sagas whose
     * created_at is older than $now - $startTimeoutSeconds (their awaiting_since
     * is still NULL, hence invisible to the primary sweep).
     *
     * @return iterable<EnrollmentSaga>
     */
    public function dueForStartSweep(\DateTimeImmutable $now, int $startTimeoutSeconds): iterable;

    public function findBySubscriptionId(int $subscriptionId): ?EnrollmentSaga;
}
