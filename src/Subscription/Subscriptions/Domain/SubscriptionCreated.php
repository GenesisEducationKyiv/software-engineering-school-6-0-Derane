<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\DomainEvent;

/**
 * Excludes the DB-generated id — it is unknown at record time (assigned by the
 * persistence layer after the aggregate is saved).
 *
 * @psalm-api
 */
final readonly class SubscriptionCreated implements DomainEvent
{
    public function __construct(
        public string $email,
        public string $repository,
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
        return 'subscription.created';
    }
}
