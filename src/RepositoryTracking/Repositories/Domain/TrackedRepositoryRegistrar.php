<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Port: idempotent registration of a repository in the scan registry. Called
 * from the Subscription context when a new subscription references a repository.
 *
 * @psalm-api
 */
interface TrackedRepositoryRegistrar
{
    public function ensureExists(string $fullName): void;
}
