<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Application\Event\EventPublisherInterface;

/**
 * In-memory publisher that records every published event so tests can assert
 * which facts a service emitted, without touching real observability sinks.
 */
final class RecordingEventPublisher implements EventPublisherInterface
{
    /** @var list<object> */
    public array $events = [];

    #[\Override]
    public function publish(object $event): void
    {
        $this->events[] = $event;
    }

    /**
     * @template T of object
     * @param class-string<T> $type
     * @return list<T>
     */
    public function ofType(string $type): array
    {
        return array_values(array_filter($this->events, static fn(object $e): bool => $e instanceof $type));
    }
}
