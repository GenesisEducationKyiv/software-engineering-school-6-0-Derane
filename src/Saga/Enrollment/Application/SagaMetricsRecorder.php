<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application;

/**
 * Records the event-shaped monolith saga counters (FR12/AC7). Each call increments
 * a row in the generic 006 saga_metrics counter table. The increment CALL-SITES
 * live where the events happen (D5 cross-ref):
 *
 *  - recordWelcomeCommandPublished() — at the D1 relay, after a confirmed publish.
 *  - recordWelcomeReplyConsumed()     — at the D3 reply consumer, for EVERY
 *    well-formed reply (including no-ops).
 *  - recordWelcomeReplyNoop()         — additionally, on the both-rowCount()=0
 *    no-op subset (consumed - noop = state-changing replies).
 *  - recordTimeoutSwept()             — at the D4 sweeper, per compensated saga.
 *
 * The state-shaped counters (confirmed_total / cancelled_total) are NOT here — they
 * are COUNTs over the saga table read through EnrollmentSagaCountPort (Saga.Domain).
 *
 * @psalm-api
 */
interface SagaMetricsRecorder
{
    public function recordWelcomeCommandPublished(): void;

    public function recordWelcomeReplyConsumed(): void;

    public function recordWelcomeReplyNoop(): void;

    public function recordTimeoutSwept(): void;
}
