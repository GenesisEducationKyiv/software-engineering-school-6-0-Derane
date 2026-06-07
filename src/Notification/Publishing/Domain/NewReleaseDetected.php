<?php

declare(strict_types=1);

namespace App\Notification\Publishing\Domain;

use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\DomainEvent;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * In-process domain event raised by Scanning's ScanReleasesHandler the moment a
 * new release is detected, dispatched synchronously on the PSR-14 plane (A3)
 * BEFORE the scan marker advances (architecture §5/§8 — "AR-FLOW2: pre-commit
 * synchronous dispatch, no outbox"). WhenNewReleaseDetectedThenPublishReleaseEmails
 * (this context) reacts to it by resolving subscribers and publishing one
 * SendReleaseEmail per recipient; a publish failure must propagate out of this
 * event's dispatch so the marker stays un-advanced and the release is re-detected
 * next cycle.
 *
 * Deliberately carries the real Releases\Sourcing\Domain\Release — NOT a
 * ReleaseSnapshot. Unlike SendReleaseEmail (a wire-serialized integration message
 * that must stay decoupled from this context's internal types), a DomainEvent
 * "never leaves the process" (see DomainEvent's docblock): there is no
 * decoupling reason to wrap it, and the architecture spec types this event
 * explicitly as NewReleaseDetected{repository, release} using the GitHub-sourced
 * Release (epics.md AR-FLOW1; architecture.md §5 sequence diagram). This is a
 * deliberate, narrow NotificationPublishing.Domain -> Releases.Domain grant —
 * see deptrac.yaml's comment at that ruleset for why it differs from (and is
 * narrower than) the SendReleaseEmail/ReleaseSnapshot decoupling rule.
 *
 * Owned/defined here (Notification\Publishing\Domain) rather than in Scanning
 * because Scanning is a pure orchestration context with no Domain layer of its
 * own (B4) — the event is raised by Scanning.Application but belongs to the
 * context that names it and reacts to it, mirroring SubscriptionCreated living
 * in Subscription.Domain even before any listener consumed it.
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
