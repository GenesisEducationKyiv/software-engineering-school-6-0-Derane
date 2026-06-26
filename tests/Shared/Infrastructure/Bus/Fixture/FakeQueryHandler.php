<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Bus\Fixture;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;

/**
 * In-test query handler that returns a fixed FakeResponse instance, so the test
 * can prove the bus returned the exact typed Response the handler produced.
 *
 * @implements QueryHandler<FakeQuery, FakeResponse>
 */
final class FakeQueryHandler implements QueryHandler
{
    public function __construct(private readonly FakeResponse $response)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        return $this->response;
    }
}
