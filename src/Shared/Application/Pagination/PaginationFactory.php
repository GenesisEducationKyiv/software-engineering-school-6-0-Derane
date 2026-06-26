<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

use App\Shared\Domain\ValueObject\Pagination;

/** @psalm-api */
final readonly class PaginationFactory implements PaginationFactoryInterface
{
    private const DEFAULT_LIMIT = 100;
    private const MAX_LIMIT = 100;

    #[\Override]
    public function fromRequest(int $limit, int $offset): Pagination
    {
        $normalizedLimit = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
        $normalizedOffset = max(0, $offset);

        return new Pagination($normalizedLimit, $normalizedOffset);
    }
}
