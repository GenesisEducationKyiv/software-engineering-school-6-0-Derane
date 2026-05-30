<?php

declare(strict_types=1);

namespace Tests\Observability\Event;

use App\Application\Event\ReleaseDetected;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\SubscriptionCreated;
use App\Observability\Event\ApplicationEventLogger;
use App\Observability\Event\ObservabilityListenerProvider;
use App\Observability\Event\ScanMetricsListener;
use App\Observability\Metrics\ScanMetrics;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ObservabilityListenerProviderTest extends TestCase
{
    private function provider(): ObservabilityListenerProvider
    {
        return new ObservabilityListenerProvider(
            new ScanMetricsListener($this->createMock(ScanMetrics::class)),
            new ApplicationEventLogger(new NullLogger())
        );
    }

    public function testMetricBearingEventGetsMetricAndLogListeners(): void
    {
        $listeners = $this->provider()->getListenersForEvent(new ReleaseDetected('golang/go', 'v1.22', null));

        $this->assertCount(2, $listeners);
        foreach ($listeners as $listener) {
            $this->assertIsCallable($listener);
        }
    }

    public function testCycleCompletedIsAlsoMetricBearing(): void
    {
        $this->assertCount(2, $this->provider()->getListenersForEvent(new ScanCycleCompleted(3, 1.0)));
    }

    public function testLogOnlyEventGetsSingleListener(): void
    {
        $listeners = $this->provider()->getListenersForEvent(new SubscriptionCreated('user@example.com', 'golang/go'));

        $this->assertCount(1, $listeners);
    }

    public function testUnknownEventGetsNoListeners(): void
    {
        $this->assertCount(0, $this->provider()->getListenersForEvent(new \stdClass()));
    }
}
