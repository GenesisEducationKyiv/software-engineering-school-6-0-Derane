<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\Start;

use App\Saga\Enrollment\Domain\EnrollmentSaga;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Saga\Enrollment\Domain\EnrollmentSagaStore;
use App\Shared\Domain\ValueObject\SagaId;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * The in-tx starter the Subscription write path depends on (through the
 * EnrollmentSagaStarter port). It runs INSIDE T1's TransactionManager closure, so
 * the subscription INSERT and the saga INSERT commit together — no subscription
 * without a saga, no saga without a subscription (FR2/FR3).
 *
 * The aggregate drives the start: it is constructed (recording SagaStarted),
 * persisted via the store, and — only when a row was actually inserted (a duplicate
 * POST is an ON CONFLICT no-op) — its domain events are dispatched on the in-process
 * PSR-14 plane. Dispatch happens within the caller's transaction, so the saga
 * listeners are deliberately best-effort observability ({@see LogSagaTransition}): a
 * listener never throws, hence never rolls back the atomic start.
 *
 * @psalm-api
 */
final readonly class StartEnrollmentSagaService implements EnrollmentSagaStarter
{
    public function __construct(
        private EnrollmentSagaStore $store,
        private EventDispatcherInterface $eventDispatcher
    ) {
    }

    #[\Override]
    public function start(int $subscriptionId): void
    {
        $saga = EnrollmentSaga::start(SagaId::generate(), $subscriptionId);

        if (!$this->store->add($saga)) {
            return;
        }

        foreach ($saga->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
