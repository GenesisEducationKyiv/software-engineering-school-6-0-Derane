<?php

declare(strict_types=1);

namespace Tests\Shared\Domain\Aggregate\Fixture;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\DomainEvent;

/**
 * In-test aggregate that drives the protected recordThat() through realistic
 * behavior (no Reflection): callers invoke doSomething(), the aggregate records.
 */
final class FakeAggregate extends AggregateRoot
{
    public function doSomething(DomainEvent $event): void
    {
        $this->recordThat($event);
    }
}
