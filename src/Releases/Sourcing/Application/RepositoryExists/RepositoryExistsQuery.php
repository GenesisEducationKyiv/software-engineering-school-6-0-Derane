<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\RepositoryExists;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class RepositoryExistsQuery implements Query
{
    public function __construct(public string $repository)
    {
    }
}
