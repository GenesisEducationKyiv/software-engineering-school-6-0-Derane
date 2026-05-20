<?php

declare(strict_types=1);

namespace App\GitHub;

use App\Domain\Release;

interface LatestReleaseCacheInterface
{
    public function getLatestRelease(string $repository): ?Release;

    public function putLatestRelease(string $repository, Release $release): void;
}
