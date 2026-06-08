<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\QueryNotRegistered;
use App\Shared\Domain\Bus\Query\Response;

/** @psalm-api */
final readonly class InMemoryQueryBus implements QueryBus
{
    /**
     * @param array<class-string<Query>, QueryHandler> $handlers
     */
    public function __construct(private array $handlers)
    {
    }

    #[\Override]
    public function ask(Query $query): Response
    {
        $handler = $this->handlers[$query::class] ?? throw new QueryNotRegistered($query);

        return $handler($query);
    }
}
