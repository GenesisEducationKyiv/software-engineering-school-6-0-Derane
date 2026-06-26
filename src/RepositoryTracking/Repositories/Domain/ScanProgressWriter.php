<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Port: records scan progress for a tracked repository — either a heartbeat
 * (markChecked) or a new release marker (markReleaseSeen).
 *
 * @psalm-api
 */
interface ScanProgressWriter
{
    public function markChecked(string $fullName): void;

    public function markReleaseSeen(string $fullName, string $tag): void;
}
