<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Metrics;

interface MetricsServiceInterface
{
    public function collect(): string;
}
