<?php

declare(strict_types=1);

namespace App\Config\Factory;

use App\Config\Pagination;

/** @psalm-api */
interface PaginationFactoryInterface
{
    public function fromRequest(int $limit, int $offset): Pagination;
}
