<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Not readonly: PHP forbids readonly child classes of non-readonly parents;
 * AggregateRoot is non-readonly.
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
