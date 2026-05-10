<?php

declare(strict_types=1);

namespace App\Metrics;

final class PrometheusFormatter
{
    /** @param list<Metric> $metrics */
    public function format(array $metrics): string
    {
        $output = '';
        foreach ($metrics as $metric) {
            $output .= "# HELP {$metric->name} {$metric->help}\n";
            $output .= "# TYPE {$metric->name} {$metric->type}\n";
            $output .= $this->formatSample($metric);
        }

        return $output;
    }

    private function formatSample(Metric $metric): string
    {
        if (!$metric->hasLabels()) {
            return "{$metric->name} {$metric->value}\n";
        }

        $pairs = [];
        foreach ($metric->labels as $key => $value) {
            $pairs[] = "{$key}=\"{$value}\"";
        }

        return "{$metric->name}{" . implode(',', $pairs) . "} {$metric->value}\n";
    }
}
