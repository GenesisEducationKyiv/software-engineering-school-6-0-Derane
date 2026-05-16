<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;

interface GitHubServiceInterface
{
    public function repositoryExists(string $repository): bool;

    public function getLatestRelease(string $repository): ?Release;
}
