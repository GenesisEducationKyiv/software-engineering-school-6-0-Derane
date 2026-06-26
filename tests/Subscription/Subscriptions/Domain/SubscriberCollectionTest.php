<?php

declare(strict_types=1);

namespace Tests\Subscription\Subscriptions\Domain;

use App\Subscription\Subscriptions\Domain\SubscriberCollection;
use App\Subscription\Subscriptions\Domain\SubscriberRef;
use PHPUnit\Framework\TestCase;

final class SubscriberCollectionTest extends TestCase
{
    public function testCountsAndIteratesItsSubscribersInOrder(): void
    {
        $collection = new SubscriberCollection([
            new SubscriberRef(1, 'a@b.com'),
            new SubscriberRef(2, 'c@d.com'),
            new SubscriberRef(3, 'e@f.com'),
        ]);

        $this->assertCount(3, $collection);

        $ids = array_map(static fn(SubscriberRef $s): int => $s->id, iterator_to_array($collection));
        $this->assertSame([1, 2, 3], $ids);
    }

    public function testEmptyCollectionCountsZeroAndYieldsNothing(): void
    {
        $collection = new SubscriberCollection([]);

        $this->assertCount(0, $collection);
        $this->assertSame([], iterator_to_array($collection));
    }
}
