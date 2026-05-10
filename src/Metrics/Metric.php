<?php

declare(strict_types=1);

namespace App\Metrics;

abstract class Metric
{
    /** @param array<string, string> $labels */
    public function __construct(
        public readonly string $name,
        public readonly string $help,
        public readonly string $type,
        public readonly int|float $value,
        public readonly array $labels = []
    ) {
    }

    public function hasLabels(): bool
    {
        return $this->labels !== [];
    }
}
