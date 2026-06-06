<?php

declare(strict_types=1);

namespace App\Shared\Application\Pagination;

use App\Shared\Domain\ValueObject\Pagination;

/** @psalm-api */
interface PaginationFactoryInterface
{
    public function fromRequest(int $limit, int $offset): Pagination;
}
