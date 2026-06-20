<?php

declare(strict_types=1);

namespace Tests\Integration\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaCountPort;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\SagaState;
use App\Shared\Domain\ValueObject\SagaId;
use PDO;
use Tests\Integration\IntegrationTestCase;

/**
 * Real-Postgres coverage of the saga state store: idempotent start, conditional
 * transitions (FR9 writer no-op), the Started-accepting complete()/compensate(),
 * and the deterministic $now-bound sweeps (M5).
 */
final class PdoEnrollmentSagaRepositoryTest extends IntegrationTestCase
{
    private EnrollmentSagaStarter $starter;
    private EnrollmentSagaReader $reader;
    private EnrollmentSagaWriter $writer;
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->starter = $this->c->get(EnrollmentSagaStarter::class);
        $this->reader = $this->c->get(EnrollmentSagaReader::class);
        $this->writer = $this->c->get(EnrollmentSagaWriter::class);
        $this->pdo = $this->c->get(PDO::class);
        $this->pdo->exec('TRUNCATE enrollment_sagas RESTART IDENTITY');
    }

    public function testStartIsIdempotentPerSubscription(): void
    {
        $this->starter->start(101);
        $this->starter->start(101);

        $this->assertSame(1, $this->countFor(101));

        $saga = $this->reader->findBySubscriptionId(101);
        $this->assertNotNull($saga);
        $this->assertSame(SagaState::Started, $saga->state());
        $this->assertNull($saga->awaitingSince());
    }

    public function testCompleteFromAwaitingConfirmationIsIdempotent(): void
    {
        $this->starter->start(102);
        $sagaId = $this->sagaIdFor(102);
        $this->assertTrue($this->writer->markPublished($sagaId));

        // First complete() transitions; the second is a state-guarded no-op (FR9).
        $this->assertTrue($this->writer->complete($sagaId));
        $this->assertSame(SagaState::Completed, $this->reader->findBySubscriptionId(102)?->state());

        $this->assertFalse($this->writer->complete($sagaId));
        $this->assertSame(SagaState::Completed, $this->reader->findBySubscriptionId(102)?->state());
    }

    public function testCompleteAcceptsStartedAsAPreState(): void
    {
        // The reply-before-relay hole: a sent reply can arrive while still Started.
        $this->starter->start(103);
        $sagaId = $this->sagaIdFor(103);

        $this->assertTrue($this->writer->complete($sagaId));
        $this->assertSame(SagaState::Completed, $this->reader->findBySubscriptionId(103)?->state());
    }

    public function testCompensateAcceptsStartedAndAwaitingConfirmation(): void
    {
        $this->starter->start(104);
        $this->assertTrue($this->writer->compensate($this->sagaIdFor(104)));
        $this->assertSame(SagaState::Compensated, $this->reader->findBySubscriptionId(104)?->state());

        $this->starter->start(105);
        $this->writer->markPublished($this->sagaIdFor(105));
        $this->assertTrue($this->writer->compensate($this->sagaIdFor(105)));
        $this->assertSame(SagaState::Compensated, $this->reader->findBySubscriptionId(105)?->state());
    }

    public function testMarkPublishedSetsAwaitingSinceAndIsConditional(): void
    {
        $this->starter->start(106);
        $sagaId = $this->sagaIdFor(106);

        $this->assertTrue($this->writer->markPublished($sagaId));
        $saga = $this->reader->findBySubscriptionId(106);
        $this->assertNotNull($saga);
        $this->assertSame(SagaState::AwaitingConfirmation, $saga->state());
        $this->assertNotNull($saga->awaitingSince());

        // Already advanced -> second markPublished is a no-op.
        $this->assertFalse($this->writer->markPublished($sagaId));
    }

    public function testDueForRelayReturnsStartedSagasOnly(): void
    {
        $this->starter->start(107);
        $this->starter->start(108);
        $this->writer->markPublished($this->sagaIdFor(108));

        $subs = $this->subscriptionIds($this->reader->dueForRelay(10));

        $this->assertContains(107, $subs);
        $this->assertNotContains(108, $subs);
    }

    public function testDueForSweepBindsTheInjectedNowNotSqlNow(): void
    {
        $this->starter->start(109);
        $this->writer->markPublished($this->sagaIdFor(109)); // awaiting_since = NOW()

        // A $now FAR in the future -> the saga is past awaiting_since + 900s -> swept.
        $future = new \DateTimeImmutable('+2 hours');
        $sweptFuture = $this->subscriptionIds($this->reader->dueForSweep($future, 900));
        $this->assertContains(109, $sweptFuture);

        // The negative guard (D4): with $now = real now, awaiting_since is fresh ->
        // NOT past the 900s deadline -> NOT swept. Deterministic only because the SQL
        // binds the passed $now, never SQL NOW().
        $now = new \DateTimeImmutable();
        $sweptNow = $this->subscriptionIds($this->reader->dueForSweep($now, 900));
        $this->assertNotContains(109, $sweptNow);
    }

    public function testDueForStartSweepBindsTheInjectedNowAndTargetsStartedSagas(): void
    {
        $this->starter->start(110); // state = started, awaiting_since NULL

        $future = new \DateTimeImmutable('+2 hours');
        $swept = $this->subscriptionIds($this->reader->dueForStartSweep($future, 900));
        $this->assertContains(110, $swept);

        $now = new \DateTimeImmutable();
        $notSwept = $this->subscriptionIds($this->reader->dueForStartSweep($now, 900));
        $this->assertNotContains(110, $notSwept);
    }

    public function testRecordRelayFailureIncrementsAttemptsWithoutChangingState(): void
    {
        $this->starter->start(111);
        $sagaId = $this->sagaIdFor(111);

        $this->writer->recordRelayFailure($sagaId, 'broker unreachable');
        $this->writer->recordRelayFailure($sagaId, 'broker unreachable again');

        $this->assertSame(SagaState::Started, $this->reader->findBySubscriptionId(111)?->state());

        $stmt = $this->pdo->prepare(
            'SELECT attempts, last_error FROM enrollment_sagas WHERE subscription_id = :id'
        );
        $stmt->execute([':id' => 111]);
        /** @var array{attempts: int|string, last_error: string} $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertSame(2, (int) $row['attempts']);
        $this->assertSame('broker unreachable again', $row['last_error']);
    }

    public function testCountPortReadsCompletedAndCompensatedTotals(): void
    {
        /** @var EnrollmentSagaCountPort $counts */
        $counts = $this->c->get(EnrollmentSagaCountPort::class);

        $this->assertSame(0, $counts->confirmedTotal());
        $this->assertSame(0, $counts->cancelledTotal());

        // Two completed, one compensated.
        $this->starter->start(201);
        $this->writer->complete($this->sagaIdFor(201));
        $this->starter->start(202);
        $this->writer->complete($this->sagaIdFor(202));
        $this->starter->start(203);
        $this->writer->compensate($this->sagaIdFor(203));

        $this->assertSame(2, $counts->confirmedTotal());
        $this->assertSame(1, $counts->cancelledTotal());
    }

    private function countFor(int $subscriptionId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM enrollment_sagas WHERE subscription_id = :id');
        $stmt->execute([':id' => $subscriptionId]);

        return (int) $stmt->fetchColumn();
    }

    private function sagaIdFor(int $subscriptionId): SagaId
    {
        $saga = $this->reader->findBySubscriptionId($subscriptionId);
        $this->assertNotNull($saga);

        return $saga->id();
    }

    /**
     * @param iterable<EnrollmentSaga> $sagas
     * @return list<int>
     */
    private function subscriptionIds(iterable $sagas): array
    {
        $ids = [];
        foreach ($sagas as $saga) {
            $ids[] = $saga->subscriptionId();
        }

        return $ids;
    }
}
