<?php

declare(strict_types=1);

namespace Tests\Saga\Enrollment\Application\Sweep;

use App\Saga\Enrollment\Application\SagaMetricsRecorder;
use App\Saga\Enrollment\Application\Sweep\SweepTimedOutSagas;
use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\SagaState;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TransactionManager;
use App\Shared\Domain\ValueObject\SagaId;
use App\Subscription\Subscriptions\Domain\SubscriptionConfirmationWriter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The sweeper compensates due sagas via the orchestrator path (subscription cancel
 * + saga compensate in one transaction) and increments timeout_swept_total per
 * compensated saga (D4/AC4). It runs BOTH queries — primary T over
 * AwaitingConfirmation, secondary T_start over Started — with the injected Clock's
 * $now bound and passed to the readers (M5). The negative-guard (a saga inside the
 * envelope is NOT due) is enforced by B1's $now-bound SQL; here the reader's due
 * set is the unit under test boundary.
 */
final class SweepTimedOutSagasTest extends TestCase
{
    private const NOW = '2026-06-20T12:00:00+00:00';

    private EnrollmentSagaReader&MockObject $reader;
    private EnrollmentSagaWriter&MockObject $writer;
    private SubscriptionConfirmationWriter&MockObject $subscriptionWriter;
    private SagaMetricsRecorder&MockObject $metrics;
    private SweepTimedOutSagas $useCase;

    protected function setUp(): void
    {
        $this->reader = $this->createMock(EnrollmentSagaReader::class);
        $this->writer = $this->createMock(EnrollmentSagaWriter::class);
        $this->subscriptionWriter = $this->createMock(SubscriptionConfirmationWriter::class);
        $this->metrics = $this->createMock(SagaMetricsRecorder::class);

        $clock = new class (new \DateTimeImmutable(self::NOW)) implements Clock {
            public function __construct(private \DateTimeImmutable $now)
            {
            }

            #[\Override]
            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };

        // A TransactionManager that simply runs the closure (no real DB).
        $transactionManager = new class implements TransactionManager {
            #[\Override]
            public function transactional(callable $work): mixed
            {
                return $work();
            }
        };

        $this->useCase = new SweepTimedOutSagas(
            $this->reader,
            $this->writer,
            $this->subscriptionWriter,
            $transactionManager,
            $clock,
            $this->metrics
        );
    }

    public function testCompensatesPrimaryAndStartSweepSagasViaTheOrchestratorPathWithTheBoundNow(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $primary = $this->aSaga('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', 1, SagaState::AwaitingConfirmation);
        $secondary = $this->aSaga('11111111-1111-4111-8111-111111111111', 2, SagaState::Started);

        $this->reader->expects($this->once())->method('dueForSweep')
            ->with($this->equalTo($now), 900)->willReturn([$primary]);
        $this->reader->expects($this->once())->method('dueForStartSweep')
            ->with($this->equalTo($now), 900)->willReturn([$secondary]);

        // Both writes per saga: subscription cancel (the single-writer lock) + saga compensate.
        $cancelled = [];
        $this->subscriptionWriter->expects($this->exactly(2))->method('cancel')
            ->willReturnCallback(static function (int $id) use (&$cancelled): bool {
                $cancelled[] = $id;

                return true;
            });
        $compensated = [];
        $this->writer->expects($this->exactly(2))->method('compensate')
            ->willReturnCallback(static function (SagaId $id) use (&$compensated): bool {
                $compensated[] = $id->value();

                return true;
            });

        // timeout_swept_total increments once per compensated saga.
        $this->metrics->expects($this->exactly(2))->method('recordTimeoutSwept');

        $this->useCase->sweep(900, 900);

        $this->assertSame([1, 2], $cancelled);
        $this->assertSame(
            ['5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', '11111111-1111-4111-8111-111111111111'],
            $compensated
        );
    }

    public function testDoesNotCountASagaConcurrentlyCompletedBetweenReadAndSweep(): void
    {
        // A `sent` reply landed between dueForSweep reading the row and the sweep tx
        // committing: the saga is already `Completed`, so both paired conditional
        // UPDATEs are rowCount()=0 no-ops. The saga was NOT swept — timeout_swept_total
        // must NOT increment (H2: metric guarded on the paired-UPDATE result).
        $raced = $this->aSaga('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', 1, SagaState::AwaitingConfirmation);

        $this->reader->method('dueForSweep')->willReturn([$raced]);
        $this->reader->method('dueForStartSweep')->willReturn([]);

        // Both UPDATEs no-op (the subscription is already confirmed; the saga completed).
        $this->subscriptionWriter->expects($this->once())->method('cancel')->willReturn(false);
        $this->writer->expects($this->once())->method('compensate')->willReturn(false);

        $this->metrics->expects($this->never())->method('recordTimeoutSwept');

        $this->useCase->sweep(900, 900);
    }

    public function testCountsASagaWhenOnlyTheSagaRowTransitionsButTheSubscriptionDidNot(): void
    {
        // Defensive: if only the saga row UPDATE takes effect (the subscription guard
        // already moved) the saga still reached `compensated`, so it IS counted —
        // `$subscriptionChanged || $sagaChanged`.
        $saga = $this->aSaga('5f1c0e2a-9b3d-4c7a-8e21-7c9b0d4e1f33', 1, SagaState::AwaitingConfirmation);

        $this->reader->method('dueForSweep')->willReturn([$saga]);
        $this->reader->method('dueForStartSweep')->willReturn([]);

        $this->subscriptionWriter->expects($this->once())->method('cancel')->willReturn(false);
        $this->writer->expects($this->once())->method('compensate')->willReturn(true);

        $this->metrics->expects($this->once())->method('recordTimeoutSwept');

        $this->useCase->sweep(900, 900);
    }

    public function testNothingDueCompensatesNothingAndIncrementsNoCounter(): void
    {
        $this->reader->method('dueForSweep')->willReturn([]);
        $this->reader->method('dueForStartSweep')->willReturn([]);

        $this->writer->expects($this->never())->method('compensate');
        $this->subscriptionWriter->expects($this->never())->method('cancel');
        $this->metrics->expects($this->never())->method('recordTimeoutSwept');

        $this->useCase->sweep(900, 900);
    }

    public function testPassesTheConfiguredDeadlinesThroughToEachReader(): void
    {
        $now = new \DateTimeImmutable(self::NOW);

        $this->reader->expects($this->once())->method('dueForSweep')
            ->with($this->equalTo($now), 600)->willReturn([]);
        $this->reader->expects($this->once())->method('dueForStartSweep')
            ->with($this->equalTo($now), 1200)->willReturn([]);

        $this->useCase->sweep(600, 1200);
    }

    private function aSaga(string $uuid, int $subscriptionId, SagaState $state): EnrollmentSaga
    {
        return EnrollmentSaga::reconstitute(SagaId::fromString($uuid), $subscriptionId, $state, null);
    }
}
