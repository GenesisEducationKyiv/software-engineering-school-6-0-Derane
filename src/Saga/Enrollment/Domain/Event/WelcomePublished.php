<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Domain\Event;

use App\Shared\Domain\DomainEvent;

/**
 * In-process domain event recorded when the relay's confirmed publish advances
 * the saga from Started to AwaitingConfirmation.
 *
 * @psalm-api
 */
final readonly class WelcomePublished implements DomainEvent
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
        return 'saga.welcome_published';
    }
}
