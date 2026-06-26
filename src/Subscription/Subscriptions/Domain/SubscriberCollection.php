<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SubscriberRef>
 * @psalm-api
 */
final readonly class SubscriberCollection implements IteratorAggregate, Countable
{
    /** @param list<SubscriberRef> $subscribers */
    public function __construct(private array $subscribers)
    {
    }

    #[\Override]
    public function count(): int
    {
        return count($this->subscribers);
    }

    #[\Override]
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->subscribers);
    }
}
