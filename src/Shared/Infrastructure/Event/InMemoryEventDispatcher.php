<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

/**
 * Synchronous, in-memory PSR-14 event dispatcher. Listeners run in registration
 * order in the same call stack — there is no queue and no deferral.
 *
 * Listener exceptions are deliberately NOT caught: any exception thrown by a
 * listener propagates straight to the caller. This is load-bearing for the
 * outbox-free, pre-commit flow (architecture §5/§8): a process event such as
 * NewReleaseDetected is dispatched synchronously BEFORE markReleaseSeen is
 * advanced, so if a listener's downstream publish throws, the exception bubbles
 * up and the caller does not advance the marker — the release is re-scanned next
 * cycle and no notification is lost. Adding a try/catch that swallows, logs, or
 * wraps the exception here would silently break that guarantee.
 *
 * @psalm-api
 */
final readonly class InMemoryEventDispatcher implements \Psr\EventDispatcher\EventDispatcherInterface
{
    public function __construct(
        private \Psr\EventDispatcher\ListenerProviderInterface $listenerProvider,
    ) {
    }

    #[\Override]
    public function dispatch(object $event): object
    {
        /**
         * The PSR-14 ListenerProviderInterface declares only `iterable`; each
         * element is a listener callable type-compatible with $event.
         *
         * @var callable $listener
         */
        foreach ($this->listenerProvider->getListenersForEvent($event) as $listener) {
            if (
                $event instanceof \Psr\EventDispatcher\StoppableEventInterface
                && $event->isPropagationStopped()
            ) {
                break;
            }

            $listener($event); // exceptions propagate — DO NOT try/catch
        }

        return $event;
    }
}
