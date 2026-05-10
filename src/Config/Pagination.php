<?php

declare(strict_types=1);

namespace App\Config;

final class Pagination
{
    public const DEFAULT_LIMIT = 100;
    public const MAX_LIMIT = 100;

    public function __construct(
        public readonly int $limit,
        public readonly int $offset
    ) {
    }

    public static function fromRequest(int $limit, int $offset): self
    {
        $normalizedLimit = $limit <= 0 ? self::DEFAULT_LIMIT : min($limit, self::MAX_LIMIT);
        $normalizedOffset = max(0, $offset);

        return new self($normalizedLimit, $normalizedOffset);
    }
}
