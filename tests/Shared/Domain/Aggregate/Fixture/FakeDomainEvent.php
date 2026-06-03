<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Aggregate\Fixture;

use App\Shared\Domain\DomainEvent;

/**
 * Immutable in-test fake event implementing the DomainEvent contract. The `name`
 * field gives each instance a distinguishing identity so ordering can be asserted
 * by identity (assertSame), not just by count.
 */
final readonly class FakeDomainEvent implements DomainEvent
{
    public function __construct(
        private string $name,
        private \DateTimeImmutable $occurredOn = new \DateTimeImmutable('2026-06-04T00:00:00+00:00'),
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
        return $this->name;
    }
}
