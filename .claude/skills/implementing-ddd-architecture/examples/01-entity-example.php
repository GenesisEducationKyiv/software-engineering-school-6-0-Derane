<?php

declare(strict_types=1);

/**
 * Example: Rich Domain Aggregate following this project's pragmatic DDD.
 *
 * Location (real): src/RepositoryTracking/Repositories/Domain/RepositoryStatus.php
 * Layer: Domain
 * Dependencies: NONE outward (pure PHP + App\Shared\Domain only)
 *
 * Lesson: an aggregate is a RICH domain model, not an anemic data bag:
 *   - business logic lives in intention-revealing methods (NOT setters)
 *   - invariants are enforced through those methods
 *   - state changes record domain events (recordThat / pullDomainEvents)
 *   - construction goes through named constructors (existing()/reconstitute())
 *   - it extends Shared\Domain\Aggregate\AggregateRoot for the event buffer
 *
 * This mirrors the real RepositoryStatus aggregate but is namespaced under
 * Example\* so it never collides with production code.
 */

namespace Example\RepositoryTracking\Repositories\Domain;

use App\RepositoryTracking\Repositories\Domain\ReleaseSeenAdvanced;
use App\RepositoryTracking\Repositories\Domain\RepositoryChecked;
use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\ReleaseTag;

/**
 * RepositoryStatus Aggregate Root — the scan marker for one tracked repository.
 *
 * NOTE: NOT `readonly`. PHP forbids a readonly child of a non-readonly parent,
 * and AggregateRoot is intentionally non-readonly because it owns a mutable
 * domain-event buffer. The identity field stays `private readonly`; the markers
 * mutate only through business methods.
 */
final class RepositoryStatus extends AggregateRoot
{
    /**
     * Private constructor — instances are created through the named constructors
     * below, never with `new RepositoryStatus(...)` in production code.
     */
    private function __construct(
        private readonly string $fullName,
        private ?string $lastSeenTag,
        private ?string $lastCheckedAt,
    ) {
    }

    /**
     * Named constructor for a freshly tracked repository (no scan history yet).
     * Expresses intent far better than a bare constructor call.
     */
    public static function existing(string $fullName): self
    {
        return new self($fullName, null, null);
    }

    /**
     * Named constructor used by the Infrastructure factory to rebuild the
     * aggregate from a persisted row. Reconstitution records NO events — the
     * facts already happened.
     */
    public static function reconstitute(
        string $fullName,
        ?string $lastSeenTag,
        ?string $lastCheckedAt,
    ): self {
        return new self($fullName, $lastSeenTag, $lastCheckedAt);
    }

    /**
     * Business method — NOT a setter.
     *
     * Advances the "last seen release" marker and records a domain event so the
     * rest of the system can react (metrics, logging, downstream notification)
     * through the synchronous PSR-14 plane.
     *
     * The tag arrives as a validated ReleaseTag value object — constructing that
     * VO IS the validation (non-empty / non-whitespace), so this method does NOT
     * re-validate the string. It enforces the business rule (idempotency) only.
     */
    public function markReleaseSeen(ReleaseTag $tag): void
    {
        // Business invariant: don't churn the marker (or emit an event) if the
        // tag we just saw is the one already recorded.
        if ($this->lastSeenTag === $tag->value()) {
            return;
        }

        $this->lastSeenTag = $tag->value();

        $this->recordThat(new ReleaseSeenAdvanced(
            $this->fullName,
            $tag->value(),
            new \DateTimeImmutable()
        ));
    }

    /**
     * Business method recording that this repository was polled, regardless of
     * whether a new release was found. Drives "freshness" metrics downstream.
     */
    public function markChecked(): void
    {
        $this->recordThat(new RepositoryChecked($this->fullName, new \DateTimeImmutable()));
    }

    /**
     * Query method — reveals derived state without exposing a setter.
     */
    public function hasSeenRelease(): bool
    {
        return $this->lastSeenTag !== null;
    }

    /**
     * Getters expose state for read only; there is NO public setter on the
     * aggregate. The only way to change a marker is through a business method
     * above, which keeps the invariant + event recording in one place.
     */
    public function fullName(): string
    {
        return $this->fullName;
    }

    public function lastSeenTag(): ?string
    {
        return $this->lastSeenTag;
    }

    public function lastCheckedAt(): ?string
    {
        return $this->lastCheckedAt;
    }
}

/*
 * HOW THIS AGGREGATE IS USED (orchestration, in the Application layer):
 *
 *   $status = $this->reader->findByFullName($fullName) ?? RepositoryStatus::existing($fullName);
 *
 *   $status->markChecked();
 *   if ($latestTag !== null) {
 *       $status->markReleaseSeen(new ReleaseTag($latestTag)); // VO validates the tag
 *   }
 *
 *   $this->writer->save($status);
 *
 *   // Drain the recorded events onto the synchronous PSR-14 plane. Listener
 *   // exceptions propagate — a publish failure aborts marker advancement, which
 *   // is exactly why this flow stays outbox-free.
 *   foreach ($status->pullDomainEvents() as $event) {
 *       $this->eventDispatcher->dispatch($event);
 *   }
 *
 * KEY TAKEAWAYS:
 *   - Behavior + invariants + events live INSIDE the aggregate.
 *   - No setters; named constructors express intent; reconstitution is event-free.
 *   - extends AggregateRoot for recordThat()/pullDomainEvents().
 *   - Pure PHP + Shared\Domain only — no PDO, Slim, Predis, RabbitMQ, or gRPC.
 */
