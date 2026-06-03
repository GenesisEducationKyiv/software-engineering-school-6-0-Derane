<?php

declare(strict_types=1);

namespace App\Shared\Domain\Aggregate;

use App\Shared\Domain\DomainEvent;

/**
 * Base for entities that are aggregate roots: they record in-process domain
 * events as a side effect of their behavior, and expose them for the PSR-14
 * dispatcher (A3) to pull and dispatch via pullDomainEvents().
 *
 * Sanctioned exception to the project `final readonly class` default
 * (CLAUDE.md). On two counts:
 *   - NOT `final`: this is a base meant to be EXTENDED by entities such as
 *     Subscription / RepositoryStatus (Epic B). `abstract` is correct.
 *   - NOT `readonly`: it owns a MUTABLE event buffer that recordThat() appends to
 *     and pullDomainEvents() clears. CLAUDE.md already exempts required-mutable-
 *     state cases (see SafeGitHubCacheDecorator); this is exactly such a case.
 *
 * @psalm-api
 */
abstract class AggregateRoot
{
    /** @var list<DomainEvent> */
    private array $domainEvents = [];

    protected function recordThat(DomainEvent $event): void
    {
        $this->domainEvents[] = $event;
    }

    /**
     * Returns the events recorded since the last pull, in recording order
     * (FIFO), then clears the buffer so the same instance can accumulate a fresh
     * batch afterward (returns-then-clears).
     *
     * @return list<DomainEvent>
     */
    public function pullDomainEvents(): array
    {
        $events = $this->domainEvents;
        $this->domainEvents = [];

        return $events;
    }
}
