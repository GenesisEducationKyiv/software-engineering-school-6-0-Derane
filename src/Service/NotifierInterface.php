<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;

interface NotifierInterface
{
    public function notifyReleaseAvailable(string $email, string $repository, Release $release): bool;
}
