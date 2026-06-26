<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Event;

use App\Shared\Infrastructure\Event\ListenerProvider;
use PHPUnit\Framework\TestCase;
use Tests\Shared\Infrastructure\Event\Fixture\FakeEvent;
use Tests\Shared\Infrastructure\Event\Fixture\FakeStoppableEvent;
use Tests\Shared\Infrastructure\Event\Fixture\OtherFakeEvent;

final class ListenerProviderTest extends TestCase
{
    public function testReturnsMappedListenersInRegistrationOrderForAMappedEvent(): void
    {
        $first = static function (object $event): void {
        };
        $second = static function (object $event): void {
        };

        $provider = new ListenerProvider([FakeEvent::class => [$first, $second]]);

        self::assertSame(
            [$first, $second],
            iterator_to_array($provider->getListenersForEvent(new FakeEvent())),
        );
    }

    public function testReturnsEmptyForAnUnmappedEvent(): void
    {
        $provider = new ListenerProvider([FakeEvent::class => [static fn(object $event) => null]]);

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new OtherFakeEvent())));
    }

    public function testReturnsEmptyWhenTheMapIsEmpty(): void
    {
        $provider = new ListenerProvider([]);

        self::assertSame([], iterator_to_array($provider->getListenersForEvent(new FakeEvent())));
    }

    public function testMatchesByInstanceofSoAnInterfaceKeyCatchesASubtype(): void
    {
        $listener = static function (object $event): void {
        };

        // FakeStoppableEvent implements StoppableEventInterface; an interface key
        // must catch the concrete subtype (instanceof, not strict class equality).
        $provider = new ListenerProvider(
            [\Psr\EventDispatcher\StoppableEventInterface::class => [$listener]],
        );

        self::assertSame(
            [$listener],
            iterator_to_array($provider->getListenersForEvent(new FakeStoppableEvent())),
        );
    }
}
