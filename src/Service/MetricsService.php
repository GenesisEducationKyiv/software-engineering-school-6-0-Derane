<?php

declare(strict_types=1);

namespace App\Service;

use App\Domain\MetricsSnapshot;
use Prometheus\RegistryInterface;
use Prometheus\RenderTextFormat;
use Psr\Log\LoggerInterface;

/** @psalm-api */
final readonly class MetricsService implements MetricsServiceInterface
{
    /**
     * @param \Closure(): MetricsSnapshot $snapshotProvider Lazily resolves the DB-backed
     *        business snapshot. Deferred (not injected as a resolved repository) so that a
     *        database outage — including a failed PDO connection — cannot block export of the
     *        RED metrics already accumulated in the registry.
     */
    public function __construct(
        private \Closure $snapshotProvider,
        private RegistryInterface $registry,
        private LoggerInterface $logger,
        private string $version
    ) {
    }

    #[\Override]
    public function collect(): string
    {
        // app_info needs no I/O, so it is always present.
        $this->registry
            ->getOrRegisterGauge('', 'app_info', 'Application info', ['version'])
            ->set(1, [$this->version]);

        try {
            $snapshot = ($this->snapshotProvider)();

            $this->registry
                ->getOrRegisterGauge('', 'app_subscriptions_total', 'Total number of active subscriptions')
                ->set($snapshot->subscriptions);
            $this->registry
                ->getOrRegisterGauge('', 'app_repositories_total', 'Total number of tracked repositories')
                ->set($snapshot->repositories);
            $this->registry
                ->getOrRegisterGauge('', 'app_repositories_with_releases', 'Repositories with at least one release')
                ->set($snapshot->repositoriesWithReleases);
        } catch (\Throwable $e) {
            $this->logger->warning('Business metrics snapshot unavailable; exporting runtime metrics only', [
                'error' => $e->getMessage(),
            ]);
        }

        return (new RenderTextFormat())->render($this->registry->getMetricFamilySamples());
    }
}
