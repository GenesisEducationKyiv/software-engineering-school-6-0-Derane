<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

/**
 * Listener exceptions are deliberately NOT caught. Events like NewReleaseDetected
 * are dispatched before the scan marker is advanced, so a publish failure must
 * propagate to the caller — otherwise the marker advances and the notification
 * is silently lost.
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
        /** @var callable $listener */
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
