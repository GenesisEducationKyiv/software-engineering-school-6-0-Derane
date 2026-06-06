<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\GetDueForScan;

use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class DueRepositoriesResponse implements Response
{
    /** @param list<string> $repositories */
    public function __construct(public array $repositories)
    {
    }
}
