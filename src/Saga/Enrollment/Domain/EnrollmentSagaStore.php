<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

/**
 * Persists a newly-started saga aggregate. The aggregate drives the write: it
 * carries its own SagaId and Started state, so the store only translates it to a
 * row. add() is the idempotent atomic start — INSERT ... ON CONFLICT
 * (subscription_id) DO NOTHING — returning whether a row was actually inserted
 * (false on a duplicate POST), so the caller dispatches SagaStarted only for a
 * genuinely new saga.
 *
 * @psalm-api
 */
interface EnrollmentSagaStore
{
    public function add(EnrollmentSaga $saga): bool;
}
