<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * Thrown by the query bus when an asked query has no registered handler.
 *
 * \LogicException because an unbound query is a wiring/programming error — the
 * handler map was assembled incompletely in the composition root — not a runtime
 * input error. Shared-owned with zero coupling to App\Exception\*, keeping the Bus
 * Domain free of outer-layer dependencies. The message names the offending query
 * class to make the failure clear.
 *
 * @psalm-api
 */
final class QueryNotRegistered extends \LogicException
{
    public function __construct(Query $query)
    {
        parent::__construct(sprintf('No query handler registered for "%s"', $query::class));
    }
}
