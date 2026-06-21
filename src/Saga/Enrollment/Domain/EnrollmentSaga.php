<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain;

use App\Saga\Enrollment\Domain\Event\SagaCompensated;
use App\Saga\Enrollment\Domain\Event\SagaCompleted;
use App\Saga\Enrollment\Domain\Event\SagaStarted;
use App\Saga\Enrollment\Domain\Event\WelcomePublished;
use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\Exception\InvalidArgumentException;
use App\Shared\Domain\ValueObject\SagaId;

/**
 * The saga aggregate root. Mutable (unlike Subscription) because it advances
 * state. Identity is SagaId; the correlation key is the PRIMITIVE
 * int $subscriptionId (1:1 with the subscription) — never a foreign Subscription
 * value object, which preserves the Saga.Domain <-> Subscription.Domain
 * acyclicity invariant (architecture section-3).
 *
 * The aggregate encodes the legal transitions (guarding illegal ones); the PDO
 * adapter additionally enforces them atomically with conditional
 * `UPDATE ... WHERE state = :expected`.
 *
 * Not readonly: PHP forbids a readonly child of a non-readonly parent, and the
 * aggregate owns mutable state.
 *
 * @psalm-api
 */
final class EnrollmentSaga extends AggregateRoot
{
    private function __construct(
        private readonly SagaId $id,
        private readonly int $subscriptionId,
        private SagaState $state,
        private ?\DateTimeImmutable $awaitingSince
    ) {
    }

    public static function start(SagaId $id, int $subscriptionId): self
    {
        $saga = new self($id, $subscriptionId, SagaState::Started, null);
        $saga->recordThat(new SagaStarted(
            $id->value(),
            $subscriptionId,
            new \DateTimeImmutable()
        ));

        return $saga;
    }

    public static function reconstitute(
        SagaId $id,
        int $subscriptionId,
        SagaState $state,
        ?\DateTimeImmutable $awaitingSince
    ): self {
        return new self($id, $subscriptionId, $state, $awaitingSince);
    }

    /**
     * Started -> AwaitingConfirmation, on a confirmed publish. Stamps the
     * timeout anchor.
     */
    public function markPublished(\DateTimeImmutable $awaitingSince): void
    {
        if ($this->state !== SagaState::Started) {
            throw new InvalidArgumentException(
                "Cannot mark published from state '{$this->state->value}'; expected 'started'"
            );
        }

        $this->state = SagaState::AwaitingConfirmation;
        $this->awaitingSince = $awaitingSince;
        $this->recordThat(new WelcomePublished(
            $this->id->value(),
            $this->subscriptionId,
            new \DateTimeImmutable()
        ));
    }

    /**
     * Started|AwaitingConfirmation -> Completed. Accepts Started as a legal
     * pre-state: a WelcomeEmailOutcome{sent} can arrive before the relay's
     * markPublished commits (the reply-before-relay hole, architecture section-5).
     */
    public function complete(): void
    {
        $this->guardCompensatablePreState('complete');

        $this->state = SagaState::Completed;
        $this->recordThat(new SagaCompleted(
            $this->id->value(),
            $this->subscriptionId,
            new \DateTimeImmutable()
        ));
    }

    /**
     * Started|AwaitingConfirmation -> Compensated, on a terminal failure or a
     * timeout sweep. Single-step: the PRD-lifecycle Compensating phase is
     * documentation only (see SagaState), never a persisted intermediate.
     */
    public function compensate(): void
    {
        $this->guardCompensatablePreState('compensate');

        $this->state = SagaState::Compensated;
        $this->recordThat(new SagaCompensated(
            $this->id->value(),
            $this->subscriptionId,
            new \DateTimeImmutable()
        ));
    }

    public function id(): SagaId
    {
        return $this->id;
    }

    public function subscriptionId(): int
    {
        return $this->subscriptionId;
    }

    public function state(): SagaState
    {
        return $this->state;
    }

    public function awaitingSince(): ?\DateTimeImmutable
    {
        return $this->awaitingSince;
    }

    private function guardCompensatablePreState(string $transition): void
    {
        if ($this->state !== SagaState::Started && $this->state !== SagaState::AwaitingConfirmation) {
            throw new InvalidArgumentException(
                "Cannot {$transition} from state '{$this->state->value}'; "
                . "expected 'started' or 'awaiting_confirmation'"
            );
        }
    }
}
