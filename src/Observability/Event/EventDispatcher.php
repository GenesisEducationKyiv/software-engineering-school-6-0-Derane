<?php

declare(strict_types=1);

namespace App\Observability\Event;

use App\Application\Event\EventPublisherInterface;
use App\Observability\Logging\FallbackLogger;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

/**
 * Fail-open fan-out for the application-event plane, exposed to services through
 * the app-owned {@see EventPublisherInterface} (no-throw contract). Each listener
 * runs in its own try/catch so one sink fault never changes the business outcome
 * of the operation that emitted the event, and the whole fan-out — including the
 * listener-provider lookup — is guarded so a faulty provider can't escape either.
 * Failures are reported as a structured stderr line by {@see FallbackLogger} (not
 * through the domain logger, to avoid recursing into a failing sink).
 *
 * It also satisfies PSR-14 {@see EventDispatcherInterface} structurally and honours
 * {@see StoppableEventInterface}. The fail-open behaviour deliberately departs from
 * PSR-14's "let listener exceptions bubble" expectation — which is precisely why
 * services depend on {@see EventPublisherInterface}, not the generic PSR-14 type.
 *
 * @psalm-api
 */
final readonly class EventDispatcher implements EventDispatcherInterface, EventPublisherInterface
{
    public function __construct(
        private ListenerProviderInterface $listeners,
        private FallbackLogger $fallback
    ) {
    }

    #[\Override]
    public function publish(object $event): void
    {
        $this->dispatch($event);
    }

    #[\Override]
    public function dispatch(object $event): object
    {
        try {
            /** @var callable $listener */
            foreach ($this->listeners->getListenersForEvent($event) as $listener) {
                if ($event instanceof StoppableEventInterface && $event->isPropagationStopped()) {
                    break;
                }

                try {
                    $listener($event);
                } catch (\Throwable $e) {
                    $this->fallback->listenerFailed($event::class, $e);
                }
            }
        } catch (\Throwable $e) {
            // A faulty listener provider (or iteration) must not escape into the
            // business flow either; publish()'s no-throw contract covers it too.
            $this->fallback->listenerFailed($event::class, $e);
        }

        return $event;
    }
}
