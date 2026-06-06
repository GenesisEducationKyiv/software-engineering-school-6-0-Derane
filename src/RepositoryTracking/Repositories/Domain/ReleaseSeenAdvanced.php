<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\DomainEvent;

/**
 * Domain event raised by RepositoryStatus when a new release marker is advanced.
 * Carries the repository full-name and the new tag so listeners need not reload
 * state.
 *
 * @psalm-api
 */
final readonly class ReleaseSeenAdvanced implements DomainEvent
{
    public function __construct(
        public string $repository,
        public string $tag,
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
        return 'repository.release_seen_advanced';
    }
}
