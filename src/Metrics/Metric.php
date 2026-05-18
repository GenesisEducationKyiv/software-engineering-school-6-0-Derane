<?php

declare(strict_types=1);

namespace App\Metrics;

abstract readonly class Metric
{
    /** @param array<string, string> $labels */
    public function __construct(
        public string $name,
        public string $help,
        public string $type,
        public int|float $value,
        public array $labels = []
    ) {
    }

    public function hasLabels(): bool
    {
        return $this->labels !== [];
    }
}
