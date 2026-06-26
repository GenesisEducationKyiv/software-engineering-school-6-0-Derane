<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Infrastructure\Factory;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;

/** @psalm-api */
interface RepositoryStatusFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): RepositoryStatus;
}
