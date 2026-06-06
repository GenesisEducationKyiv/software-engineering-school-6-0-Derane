<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;

/**
 * RepositoryStatus aggregate root — owns the scan-progress state for a single
 * tracked repository (`last_seen_tag`, `last_checked_at`).
 *
 * `final` (leaf entity) but NOT `readonly`: extends the non-readonly AggregateRoot
 * (PHP forbids readonly child classes of non-readonly parents) and holds mutable
 * scan-state that the domain operations update in-memory before persistence.
 *
 * Two factory paths:
 *   - `reconstitute()` — rebuilds from a DB row; records no event (the state
 *     already happened). Used by the PDO factory ACL mapper.
 *   - `existing()` — creates a transient instance for command handlers that only
 *     need to record domain events without loading state (avoids a redundant
 *     SELECT when the caller already knows the operation will succeed, e.g.
 *     MarkChecked / MarkReleaseSeen after ensureExists has run).
 *
 * @psalm-api
 */
final class RepositoryStatus extends AggregateRoot
{
    private function __construct(
        private readonly string $fullName,
        private ?string $lastSeenTag,
        private ?string $lastCheckedAt,
    ) {
    }

    public static function reconstitute(
        string $fullName,
        ?string $lastSeenTag,
        ?string $lastCheckedAt,
    ): self {
        return new self($fullName, $lastSeenTag, $lastCheckedAt);
    }

    /**
     * Creates a transient aggregate for command handlers that drive a domain
     * operation without needing the full persisted state.
     */
    public static function existing(string $fullName): self
    {
        return new self($fullName, null, null);
    }

    public function markReleaseSeen(string $tag): void
    {
        $this->lastSeenTag = $tag;
        $this->recordThat(new ReleaseSeenAdvanced($this->fullName, $tag, new \DateTimeImmutable()));
    }

    public function markChecked(): void
    {
        $this->recordThat(new RepositoryChecked($this->fullName, new \DateTimeImmutable()));
    }

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
