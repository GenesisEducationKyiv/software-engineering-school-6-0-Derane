<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Matches by instanceof rather than strict class equality, so a listener
 * registered against a base class or interface also fires for subtypes.
 *
 * @psalm-api
 */
final readonly class ListenerProvider implements ListenerProviderInterface
{
    /**
     * @param array<class-string, list<callable>> $listeners
     */
    public function __construct(private array $listeners)
    {
    }

    /** @return iterable<callable> */
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        foreach ($this->listeners as $eventClass => $eventListeners) {
            if ($event instanceof $eventClass) {
                yield from $eventListeners;
            }
        }
    }
}
