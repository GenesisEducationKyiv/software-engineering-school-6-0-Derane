<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * In-process domain event recorded when a terminal failure or a timeout sweep
 * drives the saga to Compensated (subscription cancelled).
 *
 * @psalm-api
 */
final readonly class SagaCompensated implements DomainEvent
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
        return 'saga.compensated';
    }
}
