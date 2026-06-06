<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Infrastructure\Factory;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;

/**
 * ACL factory: maps a raw DB row to the RepositoryStatus aggregate via the
 * reconstitution path (no domain event recorded).
 *
 * @psalm-api
 */
interface RepositoryStatusFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): RepositoryStatus;
}
