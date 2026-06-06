<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Domain;

/** @psalm-api */
interface ReleaseSource
{
    public function repositoryExists(string $repository): bool;

    public function getLatestRelease(string $repository): ?Release;
}
