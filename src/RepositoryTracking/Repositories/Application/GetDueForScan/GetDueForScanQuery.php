<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\GetDueForScan;

use App\Shared\Domain\Bus\Query\Query;

/** @psalm-api */
final readonly class GetDueForScanQuery implements Query
{
    public function __construct(public int $limit)
    {
    }
}
