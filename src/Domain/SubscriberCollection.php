<?php

declare(strict_types=1);

namespace App\Domain;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, SubscriberRef>
 * @psalm-api
 */
final class SubscriberCollection implements IteratorAggregate, Countable
{
    /** @param list<SubscriberRef> $subscribers */
    public function __construct(private readonly array $subscribers)
    {
    }

    /** @param callable(SubscriberRef): bool $hasBeenNotified */
    public function withoutAlreadyNotified(callable $hasBeenNotified): self
    {
        return new self(array_values(array_filter(
            $this->subscribers,
            static fn(SubscriberRef $s): bool => !$hasBeenNotified($s)
        )));
    }

    public function isEmpty(): bool
    {
        return $this->subscribers === [];
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
