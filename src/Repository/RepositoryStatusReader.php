<?php

declare(strict_types=1);

namespace App\Repository;

use App\Domain\RepositoryStatus;

interface RepositoryStatusReader
{
    public function getStatus(string $fullName): ?RepositoryStatus;
}
