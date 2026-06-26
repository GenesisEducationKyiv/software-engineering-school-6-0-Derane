<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

interface RepositoryCountPort
{
    public function countAll(): int;

    public function countWithReleases(): int;
}
