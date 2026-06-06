<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/**
 * Generic paging value object: a normalized (limit, offset) window. Consumed by
 * the Subscription context now and RepositoryTracking later, so it lives in the
 * Shared kernel Domain layer alongside the other VOs (architecture §4).
 *
 * @psalm-api
 */
final readonly class Pagination
{
    public function __construct(
        public int $limit,
        public int $offset
    ) {
    }
}
