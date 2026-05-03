<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\SubscriptionRepositoryInterface;

/** @psalm-api */
final class MetricsService implements MetricsServiceInterface
{
    public function __construct(private SubscriptionRepositoryInterface $repository)
    {
    }

    #[\Override]
    public function collect(): string
    {
        $metrics = $this->repository->getMetrics();

        return $this->format([
            'app_subscriptions_total' => [
                'help' => 'Total number of active subscriptions',
                'type' => 'gauge',
                'value' => $metrics['subscriptions'],
            ],
            'app_repositories_total' => [
                'help' => 'Total number of tracked repositories',
                'type' => 'gauge',
                'value' => $metrics['repositories'],
            ],
            'app_repositories_with_releases' => [
                'help' => 'Repositories that have at least one known release',
                'type' => 'gauge',
                'value' => $metrics['repositories_with_releases'],
            ],
            'app_info' => [
                'help' => 'Application info',
                'type' => 'gauge',
                'value' => 1,
                'labels' => ['version' => '1.0.0'],
            ],
        ]);
    }

    /**
     * @param array<string, array{help: string, type: string, value: int|float,
     *                            labels?: array<string, string>}> $metrics
     */
    private function format(array $metrics): string
    {
        $output = '';

        foreach ($metrics as $name => $metric) {
            $output .= "# HELP {$name} {$metric['help']}\n";
            $output .= "# TYPE {$name} {$metric['type']}\n";

            if (isset($metric['labels'])) {
                $labels = [];
                foreach ($metric['labels'] as $k => $v) {
                    $labels[] = "{$k}=\"{$v}\"";
                }
                $output .= "{$name}{" . implode(',', $labels) . "} {$metric['value']}\n";
            } else {
                $output .= "{$name} {$metric['value']}\n";
            }
        }

        return $output;
    }
}
