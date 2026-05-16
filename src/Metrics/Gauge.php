<?php

declare(strict_types=1);

namespace App\Metrics;

final readonly class Gauge extends Metric
{
    /** @param array<string, string> $labels */
    public function __construct(string $name, string $help, int|float $value, array $labels = [])
    {
        parent::__construct($name, $help, 'gauge', $value, $labels);
    }
}
