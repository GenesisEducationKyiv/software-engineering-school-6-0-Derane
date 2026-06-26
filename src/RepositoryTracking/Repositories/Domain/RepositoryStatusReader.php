<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Port: read the current scan state of a tracked repository. Returns null when
 * the repository has not been registered yet.
 *
 * @psalm-api
 */
interface RepositoryStatusReader
{
    public function getStatus(string $fullName): ?RepositoryStatus;
}
