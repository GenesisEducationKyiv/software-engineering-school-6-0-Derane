<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\Sweep;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TransactionManager;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The timeout sweeper use-case. Compensates sagas that never received a reply,
 * driving every saga to a terminal state — never hangs (FR10/FR11/NFR3):
 *
 *  - PRIMARY sweep: AwaitingConfirmation sagas past awaiting_since + T.
 *  - SECONDARY start-sweep: Started sagas past created_at + T_start (the
 *    broker-down backstop — their awaiting_since is NULL, invisible to the
 *    primary sweep).
 *
 * Each due saga is compensated VIA THE ORCHESTRATOR PATH — the same paired
 * conditional UPDATEs as the reply-path C1 (subscription pending -> cancelled +
 * saga -> compensated) wrapped in ONE TransactionManager transaction — so a saga
 * that was concurrently completed by a just-arrived `sent` reply is a rowCount()=0
 * no-op (not double-compensated) and is NOT counted. timeout_swept_total is
 * incremented per saga the sweeper actually drives to `compensated` — guarded on
 * the paired-UPDATE result, mirroring the reply handler's `$changed` guard (H2).
 *
 * `now` comes from the injected Clock and is passed to the readers (B1 binds that
 * $now, not SQL NOW()), so the negative guard (a saga still inside the envelope is
 * NOT swept) is deterministic (M5).
 *
 * @psalm-api
 */
final readonly class SweepTimedOutSagas
{
    public function __construct(
        private EnrollmentSagaReader $reader,
        private EnrollmentSagaWriter $sagaWriter,
        private SubscriptionConfirmationWriter $subscriptionWriter,
        private TransactionManager $transactionManager,
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock,
        private SagaMetricsRecorder $metrics
    ) {
    }

    public function sweep(int $timeoutSeconds, int $startTimeoutSeconds): void
    {
        $now = $this->clock->now();

        foreach ($this->reader->dueForSweep($now, $timeoutSeconds) as $saga) {
            $this->compensate($saga);
        }

        foreach ($this->reader->dueForStartSweep($now, $startTimeoutSeconds) as $saga) {
            $this->compensate($saga);
        }
    }

    private function compensate(EnrollmentSaga $saga): void
    {
        // Same orchestrator path as the reply-side C1: the subscription
        // `status='pending'` guard is the true single-writer lock. changed = at least
        // one paired UPDATE took effect; sagaChanged = the saga row transitioned.
        $result = $this->transactionManager->transactional(
            function () use ($saga): array {
                $subscriptionChanged = $this->subscriptionWriter->cancel($saga->subscriptionId());
                $sagaChanged = $this->sagaWriter->compensate($saga->id());

                return [
                    'changed' => $subscriptionChanged || $sagaChanged,
                    'sagaChanged' => $sagaChanged,
                ];
            }
        );

        if ($result['sagaChanged']) {
            // Mirror the transition on the aggregate to emit SagaCompensated on the
            // in-process plane (only when the saga row actually moved).
            $saga->compensate();
            foreach ($saga->pullDomainEvents() as $event) {
                $this->eventDispatcher->dispatch($event);
            }
        }

        if ($result['changed']) {
            // Only count a saga this sweep actually drove to `compensated`. If a
            // just-arrived `sent` reply completed it between dueForSweep reading the
            // row and this tx committing, both UPDATEs are rowCount()=0 no-ops and
            // the saga was NOT swept — mirror the reply handler's `$changed` guard
            // so timeout_swept_total stays "per compensated saga" (arch §10, H2).
            $this->metrics->recordTimeoutSwept();
        }
    }
}
