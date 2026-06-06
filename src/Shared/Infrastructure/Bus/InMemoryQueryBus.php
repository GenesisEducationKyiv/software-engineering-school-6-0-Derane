<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Bus;

use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryBus;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\QueryNotRegistered;
use App\Shared\Domain\Bus\Query\Response;

/**
 * In-memory query bus backed by an immutable, constructor-injected map of query
 * class name -> its single handler.
 *
 * The map is supplied once at wire time (the DI container is the composition root)
 * and never mutated at runtime, keeping the class `final readonly` (same rationale
 * as A3's ListenerProvider). A QueryHandler is an object with __invoke whose
 * declared return type is Response, so `return $handler($query)` yields a Response
 * — no `mixed` leaks across the bus boundary.
 *
 * @psalm-api
 */
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
