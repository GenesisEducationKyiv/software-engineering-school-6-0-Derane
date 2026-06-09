<?php

declare(strict_types=1);

namespace Tests\Observability\Metrics;

use App\Observability\Metrics\PrometheusHttpMetrics;
use App\Observability\Metrics\SafeMetricsStorage;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use PHPUnit\Framework\TestCase;
use Prometheus\CollectorRegistry;
use Prometheus\Exception\StorageException;
use Prometheus\MetricFamilySamples;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;

class SafeMetricsStorageTest extends TestCase
{
    private TestHandler $logHandler;
    private Logger $logger;

    protected function setUp(): void
    {
        $this->logHandler = new TestHandler();
        $this->logger = new Logger('test', [$this->logHandler]);
    }

    public function testSwallowsWriteFailuresAndLogsOnce(): void
    {
        $storage = new SafeMetricsStorage($this->throwingStorage(), $this->logger);

        // None of the write paths may throw, even though the inner store always fails.
        $storage->updateCounter([]);
        $storage->updateGauge([]);
        $storage->updateHistogram([]);
        $storage->updateSummary([]);

        // Degradation is logged exactly once, not on every dropped write (no log spam).
        $records = array_filter(
            $this->logHandler->getRecords(),
            static fn($r): bool => $r->level === Level::Warning
        );
        $this->assertCount(1, $records);
        $this->assertStringContainsString('Metrics storage degraded', $this->logHandler->getRecords()[0]->message);
    }

    public function testCollectAndWipeStillPropagate(): void
    {
        $storage = new SafeMetricsStorage($this->throwingStorage(), $this->logger);

        // Reads/wipes are not on the request hot path; a failing scrape should surface
        // (target down) rather than be hidden behind an empty exposition.
        $this->expectException(StorageException::class);
        $storage->collect();
    }

    public function testDelegatesToHealthyInnerStorage(): void
    {
        $inner = new InMemory();
        $registry = new CollectorRegistry(new SafeMetricsStorage($inner, $this->logger), false);

        (new PrometheusHttpMetrics($registry))->observe('GET', '/health', 200, 0.01);

        $samples = $registry->getMetricFamilySamples();
        $names = array_map(static fn(MetricFamilySamples $s): string => $s->getName(), $samples);
        $this->assertContains('http_requests_total', $names);
        $this->assertFalse($this->logHandler->hasWarningRecords());
    }

    public function testRealRecorderNeverThrowsWhenStorageIsDown(): void
    {
        // The reviewer's scenario: the Redis store throws on write. Routed through the
        // wrapper, the recorder used inside the finally blocks must stay silent.
        $registry = new CollectorRegistry(new SafeMetricsStorage($this->throwingStorage(), $this->logger), false);

        (new PrometheusHttpMetrics($registry))->observe('GET', '/health', 200, 0.01);

        $this->assertTrue($this->logHandler->hasWarningRecords());
    }

    private function throwingStorage(): Adapter
    {
        return new class implements Adapter {
            #[\Override]
            public function collect(): array
            {
                throw new StorageException('redis down');
            }

            #[\Override]
            public function updateSummary(array $data): void
            {
                throw new StorageException('redis down');
            }

            #[\Override]
            public function updateHistogram(array $data): void
            {
                throw new StorageException('redis down');
            }

            #[\Override]
            public function updateGauge(array $data): void
            {
                throw new StorageException('redis down');
            }

            #[\Override]
            public function updateCounter(array $data): void
            {
                throw new StorageException('redis down');
            }

            #[\Override]
            public function wipeStorage(): void
            {
                throw new StorageException('redis down');
            }
        };
    }
}
