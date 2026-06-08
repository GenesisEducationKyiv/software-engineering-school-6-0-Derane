<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * Carries the real Releases\Sourcing\Domain\Release (not a ReleaseSnapshot)
 * because a DomainEvent never leaves the process — there is no decoupling
 * reason to wrap it. The deptrac.yaml explicitly grants the narrow
 * NotificationPublishing.Domain → Releases.Domain edge for this reason.
 *
 * @psalm-api
 */
final readonly class NewReleaseDetected implements DomainEvent
{
    public function __construct(
        public RepositoryName $repository,
        public Release $release,
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
