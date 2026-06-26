<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Domain;

use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Raised by Scanning when a new release is detected; consumed in-process by
 * Notification\Publishing. It is owned by Releases (the context that owns the
 * release types it carries) so the dependency arrow runs Notification → Releases,
 * never Scanning → Notification. Carries a DetectedRelease (tag guaranteed
 * present, real Release inside — not a ReleaseSnapshot) because a DomainEvent
 * never leaves the process — there is no decoupling reason to wrap it.
 *
 * @psalm-api
 */
final readonly class NewReleaseDetected implements DomainEvent
{
    public function __construct(
        public RepositoryName $repository,
        public DetectedRelease $detected,
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
        return 'release.new_release_detected';
    }
}
