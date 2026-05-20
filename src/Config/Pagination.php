<?php

declare(strict_types=1);

namespace App\Config;

final readonly class Pagination
{
    public function __construct(
        public int $limit,
        public int $offset
    ) {
    }
}
