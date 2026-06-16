<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Metrics;

final readonly class Metric
{
    /** @param array<string, string> $labels */
    public function __construct(
        public string $name,
        public string $help,
        public MetricType $type,
        public int $value,
        public array $labels = [],
    ) {
    }

    public function hasLabels(): bool
    {
        return $this->labels !== [];
    }
}
