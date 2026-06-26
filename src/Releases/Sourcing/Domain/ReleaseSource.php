<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Domain;

use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
interface ReleaseSource
{
    public function repositoryExists(RepositoryName $repository): bool;

    public function getLatestRelease(RepositoryName $repository): ?Release;
}
