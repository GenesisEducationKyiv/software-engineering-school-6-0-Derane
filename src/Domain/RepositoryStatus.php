<?php

declare(strict_types=1);

namespace App\Domain;

/** @psalm-api */
final class RepositoryStatus
{
    public function __construct(
        public readonly string $fullName,
        public readonly ?string $lastSeenTag,
        public readonly ?string $lastCheckedAt
    ) {
    }
}
