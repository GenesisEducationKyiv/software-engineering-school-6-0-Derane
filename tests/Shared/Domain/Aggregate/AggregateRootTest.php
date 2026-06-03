<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Aggregate;

use Tests\Shared\Domain\Aggregate\Fixture\FakeAggregate;
use Tests\Shared\Domain\Aggregate\Fixture\FakeDomainEvent;
use PHPUnit\Framework\TestCase;

final class AggregateRootTest extends TestCase
{
    public function testEmptyBufferPullReturnsEmptyArray(): void
    {
        $aggregate = new FakeAggregate();

        $this->assertSame([], $aggregate->pullDomainEvents());
    }

    public function testRecordTwicePullReturnsBothInOrder(): void
    {
        $aggregate = new FakeAggregate();
        $eventA = new FakeDomainEvent('a');
        $eventB = new FakeDomainEvent('b');

        $aggregate->doSomething($eventA);
        $aggregate->doSomething($eventB);

        $pulled = $aggregate->pullDomainEvents();

        $this->assertCount(2, $pulled);
        $this->assertSame($eventA, $pulled[0]);
        $this->assertSame($eventB, $pulled[1]);
    }

    public function testPullDrainsSoSecondPullReturnsEmptyArray(): void
    {
        $aggregate = new FakeAggregate();
        $aggregate->doSomething(new FakeDomainEvent('a'));
        $aggregate->doSomething(new FakeDomainEvent('b'));

        $this->assertNotEmpty($aggregate->pullDomainEvents());
        $this->assertSame([], $aggregate->pullDomainEvents());
    }

    public function testRecordAfterDrainStartsFreshBatch(): void
    {
        $aggregate = new FakeAggregate();
        $eventA = new FakeDomainEvent('a');
        $eventB = new FakeDomainEvent('b');

        $aggregate->doSomething($eventA);
        $aggregate->pullDomainEvents();
        $aggregate->doSomething($eventB);

        $pulled = $aggregate->pullDomainEvents();

        $this->assertCount(1, $pulled);
        $this->assertSame($eventB, $pulled[0]);
    }

    public function testPullReturnsZeroIndexedList(): void
    {
        $aggregate = new FakeAggregate();
        $aggregate->doSomething(new FakeDomainEvent('a'));
        $aggregate->doSomething(new FakeDomainEvent('b'));

        $this->assertTrue(array_is_list($aggregate->pullDomainEvents()));
    }

    public function testFakeEventSatisfiesContract(): void
    {
        $occurredOn = new \DateTimeImmutable('2026-06-04T12:34:56+00:00');
        $event = new FakeDomainEvent('subscription.created', $occurredOn);

        $this->assertSame($occurredOn, $event->occurredOn());
        $this->assertSame('subscription.created', $event->eventName());
    }
}
