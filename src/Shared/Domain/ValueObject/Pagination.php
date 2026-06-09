<?php

declare(strict_types=1);

namespace App\Shared\Domain\ValueObject;

/** @psalm-api */
final readonly class Pagination
{
    public function __construct(
        public int $limit,
        public int $offset
    ) {
    }
}
