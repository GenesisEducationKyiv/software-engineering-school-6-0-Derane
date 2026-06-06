<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * In-house CQRS query bus — NOT Symfony Messenger (architecture §1/§5).
 *
 * A thin driver builds a Query and asks the bus; the bus routes it to its single
 * registered handler and returns the typed Response. Read-side: always returns a
 * Response, never mixed.
 *
 * @psalm-api
 */
interface QueryBus
{
    public function ask(Query $query): Response;
}
