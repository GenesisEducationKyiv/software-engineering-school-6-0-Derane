<?php

declare(strict_types=1);

namespace App\RepositoryTracking\Repositories\Application\GetDueForScan;

use App\RepositoryTracking\Repositories\Domain\ScanCandidateSource;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;

/**
 * @implements QueryHandler<GetDueForScanQuery, DueRepositoriesResponse>
 * @psalm-api
 */
final readonly class GetDueForScanHandler implements QueryHandler
{
    public function __construct(private ScanCandidateSource $candidates)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        return new DueRepositoriesResponse(
            $this->candidates->getDueForScan($query->limit)
        );
    }
}
