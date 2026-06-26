<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Event\Fixture;

/**
 * In-test stoppable event. A listener calls stop() to flip the flag mid-dispatch;
 * the dispatcher must then skip any later listener (PSR-14 stop-propagation).
 */
final class FakeStoppableEvent implements \Psr\EventDispatcher\StoppableEventInterface
{
    private bool $stopped = false;

    public function stop(): void
    {
        $this->stopped = true;
    }

    #[\Override]
    public function isPropagationStopped(): bool
    {
        return $this->stopped;
    }
}
