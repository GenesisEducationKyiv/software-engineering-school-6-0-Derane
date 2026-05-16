<?php

declare(strict_types=1);

namespace App\Domain;

/** @psalm-api */
final readonly class RepositoryStatus
{
    public function __construct(
        public string $fullName,
        public ?string $lastSeenTag,
        public ?string $lastCheckedAt
    ) {
    }
}
