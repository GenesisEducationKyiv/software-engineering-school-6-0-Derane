<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * In-process domain event recorded when a WelcomeEmailOutcome{sent} reply drives
 * the saga to Completed (subscription confirmed).
 *
 * @psalm-api
 */
final readonly class SagaCompleted implements DomainEvent
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
        return 'saga.completed';
    }
}
