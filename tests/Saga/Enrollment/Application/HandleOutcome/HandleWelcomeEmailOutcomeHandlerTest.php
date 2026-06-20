<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Application\HandleOutcome;

use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeCommand;
use App\Saga\Enrollment\Application\HandleOutcome\HandleWelcomeEmailOutcomeHandler;
use App\Saga\Enrollment\Application\HandleOutcome\WelcomeOutcome;
use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Shared\Domain\TransactionManager;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The reply-path orchestrator: paired conditional UPDATEs in ONE transaction
 * (T3 confirm / C1 compensate), idempotent via rowCount() (FR9). The replayed-reply
 * idempotency AC (D3/AC3) is proven here with mocked writers — exactly one
 * transition across 3 deliveries; the extra copies are rowCount()=0 no-ops that
 * increment welcome_reply_noop_total.
 */
final class HandleWelcomeEmailOutcomeHandlerTest extends TestCase
{
    private const string UUID = '5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33';

    private EnrollmentSagaWriter&MockObject $sagaWriter;
    private SubscriptionConfirmationWriter&MockObject $subscriptionWriter;
    private SagaMetricsRecorder&MockObject $metrics;
    private HandleWelcomeEmailOutcomeHandler $handler;

    protected function setUp(): void
    {
        $this->sagaWriter = $this->createMock(EnrollmentSagaWriter::class);
        $this->subscriptionWriter = $this->createMock(SubscriptionConfirmationWriter::class);
        $this->metrics = $this->createMock(SagaMetricsRecorder::class);

        // A TransactionManager that simply runs the closure (no real DB) and
        // returns its value, so the handler's no-op detection is observable.
        $transactionManager = new class implements TransactionManager {
            #[\Override]
            public function transactional(callable $work): mixed
            {
                return $work();
            }
        };

        $this->handler = new HandleWelcomeEmailOutcomeHandler(
            $transactionManager,
            $this->sagaWriter,
            $this->subscriptionWriter,
            $this->metrics
        );
    }

    public function testSentConfirmsTheSubscriptionAndCompletesTheSaga(): void
    {
        $this->subscriptionWriter->expects($this->once())->method('confirm')->with(123)->willReturn(true);
        $this->subscriptionWriter->expects($this->never())->method('cancel');
        $this->sagaWriter->expects($this->once())->method('complete')->willReturn(true);
        $this->sagaWriter->expects($this->never())->method('compensate');
        $this->metrics->expects($this->never())->method('recordWelcomeReplyNoop');

        ($this->handler)(new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Sent));
    }

    public function testFailedCancelsTheSubscriptionAndCompensatesTheSaga(): void
    {
        $this->subscriptionWriter->expects($this->once())->method('cancel')->with(123)->willReturn(true);
        $this->subscriptionWriter->expects($this->never())->method('confirm');
        $this->sagaWriter->expects($this->once())->method('compensate')->willReturn(true);
        $this->sagaWriter->expects($this->never())->method('complete');
        $this->metrics->expects($this->never())->method('recordWelcomeReplyNoop');

        ($this->handler)(new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Failed));
    }

    public function testBothRowCountZeroIsANoOpThatCountsNoopAndDoesNotThrow(): void
    {
        // Redelivered/terminally-moot reply: both guarded UPDATEs are no-ops.
        $this->subscriptionWriter->method('confirm')->willReturn(false);
        $this->sagaWriter->method('complete')->willReturn(false);

        $this->metrics->expects($this->once())->method('recordWelcomeReplyNoop');

        // Must not throw — FR9 ack-and-drop.
        ($this->handler)(new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Sent));
    }

    public function testReplayedSentReplyThreeTimesConfirmsExactlyOnce(): void
    {
        // First delivery transitions (confirm true); the 2nd and 3rd find the row
        // no longer pending (confirm false) AND the saga already terminal
        // (complete false) — exactly one transition, two no-ops.
        $this->subscriptionWriter->expects($this->exactly(3))->method('confirm')->with(123)
            ->willReturnOnConsecutiveCalls(true, false, false);
        $this->sagaWriter->expects($this->exactly(3))->method('complete')
            ->willReturnOnConsecutiveCalls(true, false, false);

        // welcome_reply_noop_total increments only on the 2 replays.
        $this->metrics->expects($this->exactly(2))->method('recordWelcomeReplyNoop');

        $command = new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Sent);
        ($this->handler)($command);
        ($this->handler)($command);
        ($this->handler)($command);
    }

    public function testReplayedFailedReplyThreeTimesCancelsExactlyOnce(): void
    {
        $this->subscriptionWriter->expects($this->exactly(3))->method('cancel')->with(123)
            ->willReturnOnConsecutiveCalls(true, false, false);
        $this->sagaWriter->expects($this->exactly(3))->method('compensate')
            ->willReturnOnConsecutiveCalls(true, false, false);

        $this->metrics->expects($this->exactly(2))->method('recordWelcomeReplyNoop');

        $command = new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Failed);
        ($this->handler)($command);
        ($this->handler)($command);
        ($this->handler)($command);
    }

    public function testASentReplyArrivingBeforeTheRelayMarkPublishedStillCompletes(): void
    {
        // Reply-before-relay hole (arch §5): subscription guard passes (pending),
        // saga still Started but complete() accepts Started -> one transition, no no-op.
        $this->subscriptionWriter->expects($this->once())->method('confirm')->with(123)->willReturn(true);
        $this->sagaWriter->expects($this->once())->method('complete')->willReturn(true);
        $this->metrics->expects($this->never())->method('recordWelcomeReplyNoop');

        ($this->handler)(new HandleWelcomeEmailOutcomeCommand(self::UUID, 123, WelcomeOutcome::Sent));
    }
}
