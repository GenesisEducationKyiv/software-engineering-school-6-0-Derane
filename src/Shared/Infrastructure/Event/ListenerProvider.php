<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Event;

/**
 * PSR-14 listener provider backed by an immutable, constructor-injected map of
 * event class name -> ordered list of listener callables.
 *
 * The map is supplied once at wire time (the DI container is the composition
 * root) and never mutated at runtime — there is no on()/subscribe()/addListener()
 * method — which keeps the class `final readonly`. Matching is by `instanceof`
 * rather than strict class equality, so a listener registered against a base
 * class or marker interface also catches its subtypes (a strict superset of
 * exact-class matching, idiomatic for PSR-14).
 *
 * @psalm-api
 */
final readonly class ListenerProvider implements \Psr\EventDispatcher\ListenerProviderInterface
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
