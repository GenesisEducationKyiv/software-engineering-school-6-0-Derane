<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Metrics;

interface MetricsServiceInterface
{
    public function collect(): string;
}
