<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\HandleOutcome;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\TransactionManager;
use App\Shared\Domain\ValueObject\SagaId;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The reply-path orchestrator. Wraps ONE Postgres-A transaction (via the
 * Shared.Domain TransactionManager port) and applies the paired conditional
 * UPDATEs: the subscription status transition (the true single-writer lock) and
 * the saga-row transition (advisory). The conditional UPDATE is the atomic
 * concurrency guard; the loaded aggregate is the domain-logic guard and the source
 * of the domain event dispatched on the in-process plane.
 *
 * `sent  -> T3`: subscription pending -> confirmed, saga -> completed (SagaCompleted).
 * `failed -> C1`: subscription pending -> cancelled, saga -> compensated (SagaCompensated).
 *
 * The saga domain event is emitted only when the saga row actually transitioned
 * (`sagaChanged`), so a redelivery whose UPDATE is a rowCount()=0 no-op emits nothing.
 * When BOTH UPDATEs are no-ops (already applied, or terminally moot — a redelivery or
 * a sent-after-cancel, FR9) the handler increments welcome_reply_noop_total so the
 * no-op is observable, and returns success — the reply consumer then ack-and-drops the
 * reply. This is the sole cross-context edge Saga.Application -> Subscription.Domain.
 *
 * @implements CommandHandler<HandleWelcomeEmailOutcomeCommand>
 * @psalm-api
 */
final readonly class HandleWelcomeEmailOutcomeHandler implements CommandHandler
{
    public function __construct(
        private TransactionManager $transactionManager,
        private EnrollmentSagaReader $sagaReader,
        private EnrollmentSagaWriter $sagaWriter,
        private SubscriptionConfirmationWriter $subscriptionWriter,
        private EventDispatcherInterface $eventDispatcher,
        private SagaMetricsRecorder $metrics
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $sagaId = SagaId::fromString($command->sagaId);
        $subscriptionId = $command->subscriptionId;
        $outcome = $command->outcome;

        // Pre-state aggregate, loaded so a confirmed transition can be mirrored on it
        // (validate + source the domain event). The UPDATEs below remain the guard.
        $saga = $this->sagaReader->findBySubscriptionId($subscriptionId);

        $result = $this->transactionManager->transactional(
            fn (): array => match ($outcome) {
                WelcomeOutcome::Sent => $this->applyTransition(
                    $this->subscriptionWriter->confirm($subscriptionId),
                    $this->sagaWriter->complete($sagaId),
                ),
                WelcomeOutcome::Failed => $this->applyTransition(
                    $this->subscriptionWriter->cancel($subscriptionId),
                    $this->sagaWriter->compensate($sagaId),
                ),
            }
        );

        if ($result['sagaChanged'] && $saga !== null) {
            $this->mirrorAndDispatch($saga, $outcome);
        }

        if (!$result['changed']) {
            $this->metrics->recordWelcomeReplyNoop();
        }
    }

    /**
     * @return array{changed: bool, sagaChanged: bool}
     */
    private function applyTransition(bool $subscriptionChanged, bool $sagaChanged): array
    {
        // changed = at least one paired UPDATE took effect (drives the no-op metric);
        // sagaChanged = the saga row actually transitioned (gates the domain event).
        return ['changed' => $subscriptionChanged || $sagaChanged, 'sagaChanged' => $sagaChanged];
    }

    private function mirrorAndDispatch(EnrollmentSaga $saga, WelcomeOutcome $outcome): void
    {
        match ($outcome) {
            WelcomeOutcome::Sent => $saga->complete(),
            WelcomeOutcome::Failed => $saga->compensate(),
        };

        foreach ($saga->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
