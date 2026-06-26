<?php

declare(strict_types=1);

namespace App\Shared\Domain\Bus\Query;

/**
 * Two templates carry the concrete types through the bus: T narrows the query for
 * Psalm (as on CommandHandler) and R carries the concrete Response back, so the
 * bus returns the handler's exact Response rather than mixed. The runtime
 * signature stays on the base Query/Response types (PHP signature compatibility);
 * the narrowing is Psalm-only. These generics are required, not decoration.
 *
 * @template T of Query
 * @template R of Response
 *
 * @psalm-api
 */
interface QueryHandler
{
    /**
     * @param T $query
     *
     * @return R
     */
    public function __invoke(Query $query): Response;
}
