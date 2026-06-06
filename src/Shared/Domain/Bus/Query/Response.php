<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * Marker interface for the typed result a query handler returns through the query
 * bus.
 *
 * Deliberately empty: it only carries type identity so the bus boundary returns a
 * Response rather than mixed (the @return R on QueryHandler sharpens it to the
 * concrete subtype at each call site).
 *
 * @psalm-api
 */
interface Response
{
}
