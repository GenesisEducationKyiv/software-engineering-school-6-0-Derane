<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\HandleOutcome;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\TransactionManager;
use App\Shared\Domain\ValueObject\SagaId;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;

/**
 * The reply-path orchestrator. Wraps ONE Postgres-A transaction (via the
 * Shared.Domain TransactionManager port) and applies the paired conditional
 * UPDATEs: the subscription status transition (the true single-writer lock) and
 * the saga-row transition (advisory).
 *
 * `sent  -> T3`: subscription pending -> confirmed, saga -> completed.
 * `failed -> C1`: subscription pending -> cancelled, saga -> compensated.
 *
 * When both conditional UPDATEs return rowCount() = 0 (already applied, or
 * terminally moot) the transition was a no-op (a redelivery or a sent-after-cancel,
 * FR9): the handler increments welcome_reply_noop_total so the no-op is observable,
 * and returns success — the reply consumer then ack-and-drops the no-op reply. This
 * is the sole cross-context edge Saga.Application -> Subscription.Domain
 * (confirm/cancel).
 *
 * @implements CommandHandler<HandleWelcomeEmailOutcomeCommand>
 * @psalm-api
 */
final readonly class HandleWelcomeEmailOutcomeHandler implements CommandHandler
{
    public function __construct(
        private TransactionManager $transactionManager,
        private EnrollmentSagaWriter $sagaWriter,
        private SubscriptionConfirmationWriter $subscriptionWriter,
        private SagaMetricsRecorder $metrics
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $sagaId = SagaId::fromString($command->sagaId);
        $subscriptionId = $command->subscriptionId;
        $outcome = $command->outcome;

        // $changed = at least one of the paired conditional UPDATEs took effect.
        $changed = $this->transactionManager->transactional(
            fn (): bool => match ($outcome) {
                WelcomeOutcome::Sent => $this->confirm($sagaId, $subscriptionId),
                WelcomeOutcome::Failed => $this->compensate($sagaId, $subscriptionId),
            }
        );

        if (!$changed) {
            // Both UPDATEs were rowCount()=0 — a redelivered/late reply (FR9). The
            // bounded sent-after-cancel email is observable rather than silently
            // swallowed (arch §5/§7).
            $this->metrics->recordWelcomeReplyNoop();
        }
    }

    private function confirm(SagaId $sagaId, int $subscriptionId): bool
    {
        // Paired guarded UPDATEs; both rowCount()=0 is a successful no-op (FR9).
        // The subscription `status='pending'` guard is the true single-writer lock.
        $subscriptionChanged = $this->subscriptionWriter->confirm($subscriptionId);
        $sagaChanged = $this->sagaWriter->complete($sagaId);

        return $subscriptionChanged || $sagaChanged;
    }

    private function compensate(SagaId $sagaId, int $subscriptionId): bool
    {
        $subscriptionChanged = $this->subscriptionWriter->cancel($subscriptionId);
        $sagaChanged = $this->sagaWriter->compensate($sagaId);

        return $subscriptionChanged || $sagaChanged;
    }
}
