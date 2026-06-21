<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * In-process domain event recorded when an enrollment saga is started. Carries
 * the primitive correlation key (subscriptionId) — never a foreign Subscription
 * value object — so Saga.Domain stays free of any Subscription.Domain coupling
 * (the acyclicity invariant).
 *
 * @psalm-api
 */
final readonly class SagaStarted implements DomainEvent
{
    public function __construct(
        public string $sagaId,
        public int $subscriptionId,
        private \DateTimeImmutable $occurredOn
    ) {
    }

    #[\Override]
    public function occurredOn(): \DateTimeImmutable
    {
        return $this->occurredOn;
    }

    #[\Override]
    public function eventName(): string
    {
        return 'saga.started';
    }
}
