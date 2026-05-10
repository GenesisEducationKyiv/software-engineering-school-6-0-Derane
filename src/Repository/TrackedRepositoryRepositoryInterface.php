<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\RepositoryStatus;

interface TrackedRepositoryRepositoryInterface
{
    public function ensureExists(string $fullName): void;

    public function getStatus(string $fullName): ?RepositoryStatus;

    /** @return list<string> */
    public function getDueForScan(int $limit): array;

    public function markChecked(string $fullName): void;

    public function markReleaseSeen(string $fullName, string $tag): void;
}
