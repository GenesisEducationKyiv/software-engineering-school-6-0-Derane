<?php

declare(strict_types=1);

namespace App\Observability\Metrics;

use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Psr\Log\LoggerInterface;

/**
 * Makes metric *writes* best-effort so the observability path can never take down
 * a request, a gRPC call, or a scan cycle.
 *
 * RED metrics are recorded in `finally` blocks ({@see \App\Middleware\RequestMetricsMiddleware},
 * {@see MeasuredInvoker}, {@see \App\Service\ScannerService}). The default storage is
 * Redis-backed, and a Redis outage makes the underlying write throw — and a throw
 * from a `finally` would replace an already-successful HTTP response or mask the
 * original gRPC exception. This decorator swallows (and logs once) failures from the
 * `update*` write methods, mirroring {@see \App\Cache\SafeGitHubCacheDecorator}.
 *
 * Reads are left to propagate on purpose: `collect()` runs only when rendering
 * `/metrics`, so a storage outage there should surface as a failed scrape (target
 * down) rather than a silently empty exposition.
 *
 * @psalm-api
 */
final class SafeMetricsStorage implements Adapter
{
    private bool $writeFailureLogged = false;

    public function __construct(
        private readonly Adapter $inner,
        private readonly LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function collect(): array
    {
        return $this->inner->collect();
    }

    /**
     * @param mixed[] $data
     */
    #[\Override]
    public function updateSummary(array $data): void
    {
        $this->safely(fn() => $this->inner->updateSummary($data));
    }

    /**
     * @param mixed[] $data
     */
    #[\Override]
    public function updateHistogram(array $data): void
    {
        $this->safely(fn() => $this->inner->updateHistogram($data));
    }

    /**
     * @param mixed[] $data
     */
    #[\Override]
    public function updateGauge(array $data): void
    {
        $this->safely(fn() => $this->inner->updateGauge($data));
    }

    /**
     * @param mixed[] $data
     */
    #[\Override]
    public function updateCounter(array $data): void
    {
        $this->safely(fn() => $this->inner->updateCounter($data));
    }

    #[\Override]
    public function wipeStorage(): void
    {
        $this->inner->wipeStorage();
    }

    private function safely(callable $write): void
    {
        try {
            $write();
        } catch (\Throwable $e) {
            $this->logWriteFailure($e);
        }
    }

    private function logWriteFailure(\Throwable $e): void
    {
        if ($this->writeFailureLogged) {
            return;
        }

        $this->logger->warning('Metrics storage degraded; a metric write was dropped', [
            'error' => $e->getMessage(),
        ]);
        $this->writeFailureLogged = true;
    }
}
