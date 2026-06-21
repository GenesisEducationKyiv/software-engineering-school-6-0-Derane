<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Persistence;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaCountPort;
use App\Saga\Enrollment\Domain\EnrollmentSagaReader;
use App\Saga\Enrollment\Domain\EnrollmentSagaStore;
use App\Saga\Enrollment\Domain\EnrollmentSagaWriter;
use App\Saga\Enrollment\Domain\SagaState;
use App\Shared\Domain\Clock;
use App\Shared\Domain\ValueObject\SagaId;
use PDO;

/**
 * The durable saga state store (Postgres A), implementing the per-consumer ISP
 * ports (Store + Reader + Writer + CountPort) on the shared PDO::class.
 *
 * - add() is the idempotent atomic start: INSERT ... ON CONFLICT (subscription_id)
 *   DO NOTHING, so a duplicate POST starts no second saga. The aggregate carries its
 *   own SagaId and Started state; this runs inside the enclosing TransactionManager
 *   transaction (no inner tx of its own) and returns whether a row was inserted.
 * - Every Writer transition is a conditional UPDATE ... WHERE state IN (...) returning
 *   rowCount() > 0, so a redelivery is a state-guarded no-op (FR9).
 * - The two sweep Readers bind the PASSED $now (not SQL NOW()) so sweeping is
 *   deterministic against an injected clock (M5).
 *
 * @psalm-api
 */
final readonly class PdoEnrollmentSagaRepository implements
    EnrollmentSagaStore,
    EnrollmentSagaReader,
    EnrollmentSagaWriter,
    EnrollmentSagaCountPort
{
    public function __construct(
        private PDO $pdo,
        private Clock $clock
    ) {
    }

    #[\Override]
    public function add(EnrollmentSaga $saga): bool
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO enrollment_sagas (saga_id, subscription_id, state, created_at, updated_at)
             VALUES (:saga_id, :subscription_id, :state, :now, :now)
             ON CONFLICT (subscription_id) DO NOTHING'
        );
        $stmt->execute([
            ':saga_id' => $saga->id()->value(),
            ':subscription_id' => $saga->subscriptionId(),
            ':state' => $saga->state()->value,
            ':now' => $this->clock->now()->format(\DateTimeInterface::ATOM),
        ]);

        return $stmt->rowCount() > 0;
    }

    #[\Override]
    public function dueForRelay(int $limit): iterable
    {
        $stmt = $this->pdo->prepare(
            'SELECT saga_id, subscription_id, state, awaiting_since
             FROM enrollment_sagas
             WHERE state = :state
             ORDER BY created_at
             LIMIT :limit'
        );
        $stmt->bindValue(':state', SagaState::Started->value);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    #[\Override]
    public function dueForSweep(\DateTimeImmutable $now, int $timeoutSeconds): iterable
    {
        // PRIMARY sweep: AwaitingConfirmation sagas whose awaiting_since is older
        // than the PASSED $now minus the timeout. Binds $now (not SQL NOW()) for
        // deterministic sweeping under an injected clock (M5 / D4 negative guard).
        $stmt = $this->pdo->prepare(
            'SELECT saga_id, subscription_id, state, awaiting_since
             FROM enrollment_sagas
             WHERE state = :state
               AND awaiting_since IS NOT NULL
               AND awaiting_since < :now::timestamptz - make_interval(secs => :timeout)
             ORDER BY awaiting_since'
        );
        $stmt->bindValue(':state', SagaState::AwaitingConfirmation->value);
        $stmt->bindValue(':now', $now->format(\DateTimeInterface::ATOM));
        $stmt->bindValue(':timeout', $timeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    #[\Override]
    public function dueForStartSweep(\DateTimeImmutable $now, int $startTimeoutSeconds): iterable
    {
        // SECONDARY start-sweep (broker-down backstop): Started sagas whose
        // created_at is older than the PASSED $now minus T_start. awaiting_since is
        // still NULL here, hence invisible to the primary sweep. Binds $now.
        $stmt = $this->pdo->prepare(
            'SELECT saga_id, subscription_id, state, awaiting_since
             FROM enrollment_sagas
             WHERE state = :state
               AND created_at < :now::timestamptz - make_interval(secs => :timeout)
             ORDER BY created_at'
        );
        $stmt->bindValue(':state', SagaState::Started->value);
        $stmt->bindValue(':now', $now->format(\DateTimeInterface::ATOM));
        $stmt->bindValue(':timeout', $startTimeoutSeconds, PDO::PARAM_INT);
        $stmt->execute();

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->hydrateAll($rows);
    }

    #[\Override]
    public function findBySubscriptionId(int $subscriptionId): ?EnrollmentSaga
    {
        $stmt = $this->pdo->prepare(
            'SELECT saga_id, subscription_id, state, awaiting_since
             FROM enrollment_sagas
             WHERE subscription_id = :subscription_id'
        );
        $stmt->execute([':subscription_id' => $subscriptionId]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->hydrate($row) : null;
    }

    #[\Override]
    public function markPublished(SagaId $sagaId): bool
    {
        // Started -> AwaitingConfirmation, stamps the timeout anchor.
        $stmt = $this->pdo->prepare(
            'UPDATE enrollment_sagas
             SET state = :new, awaiting_since = NOW(), updated_at = NOW()
             WHERE saga_id = :saga_id AND state = :expected'
        );
        $stmt->execute([
            ':new' => SagaState::AwaitingConfirmation->value,
            ':saga_id' => $sagaId->value(),
            ':expected' => SagaState::Started->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    #[\Override]
    public function complete(SagaId $sagaId): bool
    {
        // Started|AwaitingConfirmation -> Completed (accepts Started: the
        // reply-before-relay hole, architecture section-5).
        return $this->transition($sagaId, SagaState::Completed);
    }

    #[\Override]
    public function compensate(SagaId $sagaId): bool
    {
        return $this->transition($sagaId, SagaState::Compensated);
    }

    #[\Override]
    public function recordRelayFailure(SagaId $sagaId, string $error): void
    {
        // Pure observability: increments attempts, sets last_error. No state change.
        $stmt = $this->pdo->prepare(
            'UPDATE enrollment_sagas
             SET attempts = attempts + 1, last_error = :error, updated_at = NOW()
             WHERE saga_id = :saga_id'
        );
        $stmt->execute([':error' => $error, ':saga_id' => $sagaId->value()]);
    }

    #[\Override]
    public function confirmedTotal(): int
    {
        return $this->countByState(SagaState::Completed);
    }

    #[\Override]
    public function cancelledTotal(): int
    {
        return $this->countByState(SagaState::Compensated);
    }

    private function countByState(SagaState $state): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM enrollment_sagas WHERE state = :state'
        );
        $stmt->execute([':state' => $state->value]);

        return (int) $stmt->fetchColumn();
    }

    private function transition(SagaId $sagaId, SagaState $newState): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE enrollment_sagas
             SET state = :new, updated_at = NOW()
             WHERE saga_id = :saga_id AND state IN (:started, :awaiting)'
        );
        $stmt->execute([
            ':new' => $newState->value,
            ':saga_id' => $sagaId->value(),
            ':started' => SagaState::Started->value,
            ':awaiting' => SagaState::AwaitingConfirmation->value,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): EnrollmentSaga
    {
        $awaitingSince = $row['awaiting_since'] !== null
            ? new \DateTimeImmutable((string) $row['awaiting_since'])
            : null;

        return EnrollmentSaga::reconstitute(
            SagaId::fromString((string) $row['saga_id']),
            (int) $row['subscription_id'],
            SagaState::from((string) $row['state']),
            $awaitingSince
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<EnrollmentSaga>
     */
    private function hydrateAll(array $rows): array
    {
        return array_map(fn (array $row): EnrollmentSaga => $this->hydrate($row), $rows);
    }
}
