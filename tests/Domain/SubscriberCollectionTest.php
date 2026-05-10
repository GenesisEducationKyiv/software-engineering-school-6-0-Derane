<?php

declare(strict_types=1);

namespace Tests\Domain;

use App\Domain\SubscriberCollection;
use App\Domain\SubscriberRef;
use PHPUnit\Framework\TestCase;

final class SubscriberCollectionTest extends TestCase
{
    public function testWithoutAlreadyNotifiedFiltersOutMatches(): void
    {
        $collection = new SubscriberCollection([
            new SubscriberRef(1, 'a@b.com'),
            new SubscriberRef(2, 'c@d.com'),
            new SubscriberRef(3, 'e@f.com'),
        ]);

        $remaining = $collection->withoutAlreadyNotified(static fn(SubscriberRef $s): bool => $s->id === 2);

        $ids = array_map(static fn(SubscriberRef $s): int => $s->id, iterator_to_array($remaining));
        $this->assertSame([1, 3], $ids);
    }

    public function testReturnsNewInstanceLeavingOriginalIntact(): void
    {
        $original = new SubscriberCollection([
            new SubscriberRef(1, 'a@b.com'),
            new SubscriberRef(2, 'c@d.com'),
        ]);

        $filtered = $original->withoutAlreadyNotified(static fn(SubscriberRef $_): bool => true);

        $this->assertCount(2, $original);
        $this->assertCount(0, $filtered);
        $this->assertTrue($filtered->isEmpty());
    }
}
