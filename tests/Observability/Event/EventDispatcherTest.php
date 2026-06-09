<?php

declare(strict_types=1);

namespace Tests\Observability\Event;

use App\Observability\Event\EventDispatcher;
use App\Observability\Logging\EmailMasker;
use App\Observability\Logging\FallbackLogger;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\ListenerProviderInterface;
use Psr\EventDispatcher\StoppableEventInterface;

class EventDispatcherTest extends TestCase
{
    public function testInvokesEveryListenerWithTheEventInOrder(): void
    {
        $event = new \stdClass();
        $received = [];
        $provider = $this->providerReturning([
            function (object $e) use (&$received): void {
                $received[] = ['a', $e];
            },
            function (object $e) use (&$received): void {
                $received[] = ['b', $e];
            },
        ]);

        $result = $this->publisher($provider)->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertSame(['a', 'b'], array_column($received, 0));
        $this->assertSame([$event, $event], array_column($received, 1));
    }

    public function testReturnsEventUntouchedWhenNoListenersAreRegistered(): void
    {
        $event = new \stdClass();

        $result = $this->publisher($this->providerReturning([]))->dispatch($event);

        $this->assertSame($event, $result);
    }

    public function testAListenerFailureIsIsolatedAndRemainingListenersStillRun(): void
    {
        $ran = [];
        $provider = $this->providerReturning([
            static function (): void {
                throw new \RuntimeException('listener boom');
            },
            function () use (&$ran): void {
                $ran[] = 'second';
            },
        ]);
        $event = new \stdClass();

        $result = $this->publisher($provider)->dispatch($event);

        $this->assertSame($event, $result);
        $this->assertSame(['second'], $ran);
    }

    public function testPublishRunsListenersAndSwallowsFailures(): void
    {
        $ran = [];
        $provider = $this->providerReturning([
            static function (): void {
                throw new \RuntimeException('listener boom');
            },
            function () use (&$ran): void {
                $ran[] = 'second';
            },
        ]);

        $this->publisher($provider)->publish(new \stdClass());

        $this->assertSame(['second'], $ran);
    }

    public function testHonoursStoppableEventAndStopsAfterPropagationIsStopped(): void
    {
        $ran = [];
        $event = new class implements StoppableEventInterface {
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
        };
        $provider = $this->providerReturning([
            function () use (&$ran, $event): void {
                $ran[] = 'first';
                $event->stop();
            },
            function () use (&$ran): void {
                $ran[] = 'second';
            },
        ]);

        $this->publisher($provider)->dispatch($event);

        $this->assertSame(['first'], $ran);
    }

    public function testPublishDoesNotThrowWhenTheListenerProviderItselfFails(): void
    {
        $provider = new class implements ListenerProviderInterface {
            #[\Override]
            public function getListenersForEvent(object $event): iterable
            {
                throw new \RuntimeException('provider boom');
            }
        };

        $this->publisher($provider)->publish(new \stdClass());

        $this->addToAssertionCount(1); // reached here => the provider failure was swallowed
    }

    private function publisher(ListenerProviderInterface $listeners): EventDispatcher
    {
        return new EventDispatcher($listeners, new FallbackLogger('test', 'test', new EmailMasker()));
    }

    /**
     * @param list<callable> $listeners
     */
    private function providerReturning(array $listeners): ListenerProviderInterface
    {
        return new class ($listeners) implements ListenerProviderInterface {
            /** @param list<callable> $listeners */
            public function __construct(private array $listeners)
            {
            }

            #[\Override]
            public function getListenersForEvent(object $event): iterable
            {
                return $this->listeners;
            }
        };
    }
}
