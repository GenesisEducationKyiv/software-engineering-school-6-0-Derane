<?php

declare(strict_types=1);

namespace App\Scanning\Scanner\Application;

use App\Releases\Sourcing\Domain\Release;

interface NotifierInterface
{
    public function notifyReleaseAvailable(string $email, string $repository, Release $release): bool;
}
