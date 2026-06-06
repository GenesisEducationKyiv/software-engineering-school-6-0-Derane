<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * Marker interface for a CQRS query — a read-side request routed to exactly one
 * handler by the query bus, which returns a typed Response.
 *
 * Deliberately empty: a query is a plain, immutable request DTO. The bus keys on
 * the concrete query class, so this marker only carries type identity.
 *
 * @psalm-api
 */
interface Query
{
}
