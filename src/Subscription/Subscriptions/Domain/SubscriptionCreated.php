<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\DomainEvent;

/**
 * In-process domain event recorded by the Subscription aggregate on its create
 * path. Carries the payload a future listener (notification/analytics) needs
 * without the DB-generated id (unknown at record time — see the story's
 * "Aggregate id handling").
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
