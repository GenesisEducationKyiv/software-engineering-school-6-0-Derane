<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

use App\Shared\Domain\ValueObject\SagaId;

/**
 * Write side of the saga store (per-consumer ISP). Each transition is a
 * conditional `UPDATE ... WHERE state = :expected` and returns
 * `rowCount() > 0` so the orchestrator can treat a redelivery as a no-op (FR9).
 *
 * complete() and compensate() deliberately accept BOTH Started and
 * AwaitingConfirmation as legal pre-states: a reply can legitimately arrive
 * before the relay's markPublished UPDATE has committed (the reply-before-relay
 * hole, architecture section-5).
 *
 * @psalm-api
 */
interface EnrollmentSagaWriter
{
    /** Started -> AwaitingConfirmation, sets awaiting_since = NOW(). */
    public function markPublished(SagaId $sagaId): bool;

    /** Started|AwaitingConfirmation -> Completed. */
    public function complete(SagaId $sagaId): bool;

    /** Started|AwaitingConfirmation -> Compensated. */
    public function compensate(SagaId $sagaId): bool;

    /**
     * Relay-publish observability: increments attempts, sets last_error. No
     * state change.
     */
    public function recordRelayFailure(SagaId $sagaId, string $error): void;
}
