<?php

declare(strict_types=1);

namespace Tests\RepositoryTracking\Repositories\Domain;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;

final class RepositoryStatusMother
{
    public static function reconstituted(
        string $fullName = 'owner/repo',
        ?string $lastSeenTag = null,
        ?string $lastCheckedAt = null,
    ): RepositoryStatus {
        return RepositoryStatus::reconstitute($fullName, $lastSeenTag, $lastCheckedAt);
    }

    public static function existing(string $fullName = 'owner/repo'): RepositoryStatus
    {
        return RepositoryStatus::existing($fullName);
    }
}
