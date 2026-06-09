<?php

declare(strict_types=1);

namespace App\Releases\Sourcing\Application\RepositoryExists;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * @implements QueryHandler<RepositoryExistsQuery, RepositoryExistsResponse>
 *
 * @psalm-api
 */
final readonly class RepositoryExistsHandler implements QueryHandler
{
    public function __construct(private ReleaseSource $source)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        return new RepositoryExistsResponse(
            $this->source->repositoryExists(new RepositoryName($query->repository))
        );
    }
}
