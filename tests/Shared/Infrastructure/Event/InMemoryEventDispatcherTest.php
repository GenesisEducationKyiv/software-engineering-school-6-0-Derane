<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Event;

use App\Shared\Infrastructure\Event\InMemoryEventDispatcher;
use App\Shared\Infrastructure\Event\ListenerProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Shared\Infrastructure\Event\Fixture\FakeEvent;
use Tests\Shared\Infrastructure\Event\Fixture\FakeStoppableEvent;
use Tests\Shared\Infrastructure\Event\Fixture\OtherFakeEvent;

final class InMemoryEventDispatcherTest extends TestCase
{
    public function testSynchronousDispatchRunsTheListenerInTheSameCallStack(): void
    {
        $calls = [];
        $listener = static function (object $event) use (&$calls): void {
            $calls[] = $event;
        };

        $dispatcher = $this->dispatcherFor([FakeEvent::class => [$listener]]);

        $event = new FakeEvent();
        $dispatcher->dispatch($event);

        // Observable immediately after dispatch() returns — no queue, no deferral.
        self::assertSame([$event], $calls);
    }

    public function testDispatchReturnsTheSameEventInstance(): void
    {
        $dispatcher = $this->dispatcherFor([FakeEvent::class => [static fn(object $event) => null]]);

        $event = new FakeEvent();

        self::assertSame($event, $dispatcher->dispatch($event));
    }

    public function testListenerExceptionPropagatesAndIsNotSwallowed(): void
    {
        $dispatcher = $this->dispatcherFor([
            FakeEvent::class => [
                static fn(object $event) => throw new RuntimeException('publish failed'),
            ],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('publish failed');

        $dispatcher->dispatch(new FakeEvent());
    }

    public function testListenerAfterAThrowingListenerDoesNotRun(): void
    {
        $laterRan = false;
        $dispatcher = $this->dispatcherFor([
            FakeEvent::class => [
                static fn(object $event) => throw new RuntimeException('publish failed'),
                static function (object $event) use (&$laterRan): void {
                    $laterRan = true;
                },
            ],
        ]);

        $caught = null;
        try {
            $dispatcher->dispatch(new FakeEvent());
        } catch (RuntimeException $exception) {
            $caught = $exception;
        }

        // Stop-on-first-error: the exception reached us AND the later listener never ran.
        self::assertInstanceOf(RuntimeException::class, $caught);
        self::assertFalse($laterRan);
    }

    public function testMultipleListenersRunInRegistrationOrder(): void
    {
        $order = [];
        $dispatcher = $this->dispatcherFor([
            FakeEvent::class => [
                static function (object $event) use (&$order): void {
                    $order[] = 'A';
                },
                static function (object $event) use (&$order): void {
                    $order[] = 'B';
                },
            ],
        ]);

        $dispatcher->dispatch(new FakeEvent());

        self::assertSame(['A', 'B'], $order);
    }

    public function testUnrelatedEventDoesNotFireAListenerAndIsReturned(): void
    {
        $ran = false;
        $dispatcher = $this->dispatcherFor([
            FakeEvent::class => [
                static function (object $event) use (&$ran): void {
                    $ran = true;
                },
            ],
        ]);

        $event = new OtherFakeEvent();
        $returned = $dispatcher->dispatch($event);

        self::assertFalse($ran);
        self::assertSame($event, $returned);
    }

    public function testStopPropagationHaltsFurtherListeners(): void
    {
        $bRan = false;
        $dispatcher = $this->dispatcherFor([
            FakeStoppableEvent::class => [
                static function (FakeStoppableEvent $event): void {
                    $event->stop();
                },
                static function (object $event) use (&$bRan): void {
                    $bRan = true;
                },
            ],
        ]);

        $dispatcher->dispatch(new FakeStoppableEvent());

        self::assertFalse($bRan);
    }

    public function testNonStoppedStoppableEventLetsAllListenersRun(): void
    {
        $order = [];
        $dispatcher = $this->dispatcherFor([
            FakeStoppableEvent::class => [
                static function (object $event) use (&$order): void {
                    $order[] = 'A';
                },
                static function (object $event) use (&$order): void {
                    $order[] = 'B';
                },
            ],
        ]);

        $dispatcher->dispatch(new FakeStoppableEvent());

        self::assertSame(['A', 'B'], $order);
    }

    /**
     * @param array<class-string, list<callable>> $listeners
     */
    private function dispatcherFor(array $listeners): InMemoryEventDispatcher
    {
        return new InMemoryEventDispatcher(new ListenerProvider($listeners));
    }
}
