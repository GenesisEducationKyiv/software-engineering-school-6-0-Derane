<?php

declare(strict_types=1);

namespace App\Observability\Event;

use App\Application\Event\NotificationBatchCompleted;
use App\Application\Event\ReleaseDetected;
use App\Application\Event\ReleaseMarkerWithheld;
use App\Application\Event\ReleaseNotificationFailed;
use App\Application\Event\ReleaseNotificationSent;
use App\Application\Event\RepositoryScanFailed;
use App\Application\Event\ScanCycleCompleted;
use App\Application\Event\ScanCycleFailed;
use App\Application\Event\ScanCycleStarted;
use App\Application\Event\ScanInterruptedByRateLimit;
use App\Application\Event\SubscriptionCreated;
use App\Application\Event\SubscriptionDeleted;
use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Maps each application event to its observability sinks: the metric listener for the
 * events that carry RED/throughput signal, and the structured logger for every
 * event. This is the single place the event → sink wiring lives.
 *
 * @psalm-api
 */
final readonly class ObservabilityListenerProvider implements ListenerProviderInterface
{
    /** @var array<class-string, list<callable>> */
    private array $listeners;

    public function __construct(ScanMetricsListener $metrics, ApplicationEventLogger $logger)
    {
        $this->listeners = [
            ScanCycleStarted::class => [$logger],
            ScanCycleCompleted::class => [$metrics->onCycleCompleted(...), $logger],
            RepositoryScanFailed::class => [$metrics->onRepositoryFailed(...), $logger],
            ScanInterruptedByRateLimit::class => [$metrics->onRateLimited(...), $logger],
            ScanCycleFailed::class => [$metrics->onCycleFailed(...), $logger],
            ReleaseDetected::class => [$metrics->onReleaseDetected(...), $logger],
            NotificationBatchCompleted::class => [$metrics->onNotificationBatch(...), $logger],
            ReleaseMarkerWithheld::class => [$logger],
            ReleaseNotificationSent::class => [$logger],
            ReleaseNotificationFailed::class => [$logger],
            SubscriptionCreated::class => [$logger],
            SubscriptionDeleted::class => [$logger],
        ];
    }

    /** @return list<callable> */
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        return $this->listeners[$event::class] ?? [];
    }
}
