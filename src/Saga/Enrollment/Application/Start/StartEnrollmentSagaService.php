<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Application\Start;

use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;

/**
 * The in-tx starter façade the Subscription write path depends on (through the
 * EnrollmentSagaStarter port). It runs INSIDE T1's TransactionManager closure, so
 * the subscription INSERT and the saga INSERT commit together — no subscription
 * without a saga, no saga without a subscription (FR2/FR3).
 *
 * It decorates the durable persistence starter (the PDO repository, bound at the
 * composition root in Epic B). Keeping the façade as a separate application
 * service leaves room for the start path to grow (e.g. domain-event dispatch)
 * without touching the persistence adapter. The correlation key stays the
 * primitive int $subscriptionId.
 *
 * @psalm-api
 */
final readonly class StartEnrollmentSagaService implements EnrollmentSagaStarter
{
    public function __construct(private EnrollmentSagaStarter $persistence)
    {
    }

    #[\Override]
    public function start(int $subscriptionId): void
    {
        $this->persistence->start($subscriptionId);
    }
}
