<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\DomainEvent;

/** @psalm-api */
final readonly class RepositoryChecked implements DomainEvent
{
    public function __construct(
        public string $repository,
        private \DateTimeImmutable $occurredOn,
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
        return 'repository.checked';
    }
}
