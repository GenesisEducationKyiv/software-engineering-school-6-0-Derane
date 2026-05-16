<?php

declare(strict_types=1);

namespace App\Repository;

interface TrackedRepositoryRegistrar
{
    public function ensureExists(string $fullName): void;
}
