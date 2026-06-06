<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class FetchLatestReleaseResponse implements Response
{
    public function __construct(public ?Release $release)
    {
    }
}
