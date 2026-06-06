<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Infrastructure\Cache;

interface RepositoryExistenceCacheInterface
{
    public function getExists(string $repository): ?bool;

    public function putExists(string $repository, bool $exists): void;
}
