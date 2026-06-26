<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\FetchLatestRelease;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class FetchLatestReleaseQuery implements Query
{
    public function __construct(public string $repository)
    {
    }
}
