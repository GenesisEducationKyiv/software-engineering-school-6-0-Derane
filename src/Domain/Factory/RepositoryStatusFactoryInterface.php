<?php

declare(strict_types=1);

namespace App\Domain\Factory;

use App\Domain\RepositoryStatus;

interface RepositoryStatusFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): RepositoryStatus;
}
