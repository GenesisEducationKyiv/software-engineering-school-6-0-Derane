<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure\Cache;

use App\Releases\Sourcing\Domain\Release;

interface LatestReleaseCacheInterface
{
    public function getLatestRelease(string $repository): ?Release;

    public function putLatestRelease(string $repository, Release $release): void;
}
