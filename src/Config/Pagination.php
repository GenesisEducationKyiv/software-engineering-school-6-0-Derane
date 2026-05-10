<?php

declare(strict_types=1);

namespace App\Config;

final class Pagination
{
    public function __construct(
        public readonly int $limit,
        public readonly int $offset
    ) {
    }
}
