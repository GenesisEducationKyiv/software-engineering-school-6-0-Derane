<?php

declare(strict_types=1);

namespace Tests\Support;

use Psr\EventDispatcher\ListenerProviderInterface;

/**
 * Listener provider whose only listener always throws — used with the real
 * {@see \App\Observability\Event\EventDispatcher} to prove a listener fault
 * cannot break the business flow that emitted the event.
 */
final class ThrowingListenerProvider implements ListenerProviderInterface
{
    #[\Override]
    public function getListenersForEvent(object $event): iterable
    {
        return [
            static function (): void {
                throw new \RuntimeException('listener boom');
            },
        ];
    }
}
