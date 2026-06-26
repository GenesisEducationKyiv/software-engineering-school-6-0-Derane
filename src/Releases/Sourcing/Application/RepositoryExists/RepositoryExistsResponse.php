<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\RepositoryExists;

use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class RepositoryExistsResponse implements Response
{
    public function __construct(public bool $exists)
    {
    }
}
