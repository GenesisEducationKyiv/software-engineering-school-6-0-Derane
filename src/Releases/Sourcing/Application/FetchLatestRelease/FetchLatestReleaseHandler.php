<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;

/**
 * @implements QueryHandler<FetchLatestReleaseQuery, FetchLatestReleaseResponse>
 *
 * @psalm-api
 */
final readonly class FetchLatestReleaseHandler implements QueryHandler
{
    public function __construct(private ReleaseSource $source)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        return new FetchLatestReleaseResponse($this->source->getLatestRelease($query->repository));
    }
}
