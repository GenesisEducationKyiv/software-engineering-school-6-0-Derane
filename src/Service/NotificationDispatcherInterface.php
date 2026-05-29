<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\Release;

interface NotificationDispatcherInterface
{
    /**
     * Dispatches release notifications to pending subscribers.
     * Returns true when every attempted delivery succeeded.
     */
    public function dispatch(string $repoName, Release $release): bool;
}
