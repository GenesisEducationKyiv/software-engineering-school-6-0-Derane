<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Read-model snapshot of a tracked repository's scan progress. It has identity
 * (fullName) but no behaviour: the scan write path advances progress through the
 * ScanProgressWriter port (CRUD), so this type is only ever reconstituted for
 * reads (ReleaseDetector). It is deliberately NOT an aggregate root — there is no
 * command that mutates it and records domain events.
 *
 * @psalm-api
 */
final readonly class RepositoryStatus
{
    private function __construct(
        private string $fullName,
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
