<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * \LogicException because an unbound query is a wiring/programming error — the
 * handler map was assembled incompletely in the composition root — not a runtime
 * input error.
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
