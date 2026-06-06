<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Port: source of repositories due for a scan cycle, ordered by least-recently
 * checked first (NULLs first, then ascending last_checked_at).
 *
 * @psalm-api
 */
interface ScanCandidateSource
{
    /** @return list<string> */
    public function getDueForScan(int $limit): array;
}
