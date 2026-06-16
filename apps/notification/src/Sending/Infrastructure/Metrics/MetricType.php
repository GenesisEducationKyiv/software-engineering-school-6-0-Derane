<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Metrics;

/** Prometheus metric type as emitted on the `# TYPE` exposition line. */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
}
