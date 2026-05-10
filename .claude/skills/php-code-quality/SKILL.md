---
name: php-code-quality
description: Opinionated PHP design and review guidance. Apply SOLID + GRASP with these specific choices — anemic DTOs (not value objects with validation inside), Validators as injectable classes, factories behind interfaces (not static from* methods), narrow per-consumer Repository interfaces, Collections only where they own behavior or metadata, unified exception-to-status mapping, and disciplined DI bindings. Trigger when: reviewing or authoring PHP service/repository/controller code, replacing `array{...}` shapes with typed data, designing domain layers, splitting fat services, applying SOLID/GRASP, working on a Symfony/Slim/Laravel/PSR-style project. Skip for trivial scripts, framework configuration, or non-OOP PHP.
---

# php-code-quality

Opinionated PHP design discipline distilled from real refactoring work. The rules are **not** generic SOLID textbook — they reflect specific trade-offs that work well in PHP given its lack of generics and its DI-container culture.

## Core stance

- **DTOs, not value objects.** Use readonly classes as anemic data carriers. Validation lives in dedicated `Validator` classes that get injected, not in DTO constructors.
- **Factories behind interfaces, not static `from*` methods.** Anything that parses external data (API payloads, DB rows) goes through an injectable factory so it can be mocked.
- **ISP at the consumer.** Repository interfaces should be narrow per caller. One class implementing two interfaces is fine; one fat interface forcing every caller to see methods they don't use is not.
- **Pure Fabrications for cohesion.** Caches, renderers, formatters, exception mappers each get their own class when extracting them sharpens SRP.
- **Collections only when they earn it.** A blanket `Collection<T>` over array is pure ceremony in PHP. Add a collection when it (a) owns a domain rule like `withoutAlreadyNotified`, or (b) carries metadata like `total` + `hasNextPage`. Skip otherwise.
- **One source of truth for exception → transport status.** HTTP middleware and gRPC handler share one `ExceptionStatusMap`.
- **No concrete classes as DI keys.** Bind interfaces only. For shared instances across two interfaces, alias the second to the first.

## When triggered

1. **Audit before refactoring.** Find SOLID/GRASP violations, list them grouped by principle with file:line refs. Don't start cutting until the user agrees on scope.
2. **Identify wire-format constraints.** What's the public contract (JSON shape, gRPC reply, behat assertions)? Internal refactors must preserve these unless the user explicitly opts in to API changes.
3. **Update tests as part of the change.** Contracts at the class level changed → tests change too. Don't leave a broken suite.
4. **Re-run psalm + phpunit + lint + behat** after the change. Acceptance tests need the docker stack; ask before booting it.

## Recipes

### DTO (anemic, readonly, immutable)

```php
final class Subscription implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $email,
        public readonly string $repository,
        public readonly string $createdAt
    ) {
    }

    /** @return array{id: int, email: string, repository: string, created_at: string} */
    public function toArray(): array { /* ... */ }

    #[\Override]
    public function jsonSerialize(): array { return $this->toArray(); }
}
```

**No** `fromArray` / `fromRow` static methods on DTOs. Those go on factories (next recipe).

### Factory (interface + concrete impl)

```php
interface SubscriptionFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function fromRow(array $row): Subscription;
}

final class SubscriptionFactory implements SubscriptionFactoryInterface
{
    #[\Override]
    public function fromRow(array $row): Subscription
    {
        return new Subscription(
            (int) $row['id'],
            (string) $row['email'],
            (string) $row['repository'],
            (string) $row['created_at'],
        );
    }
}
```

Inject the interface into repositories / API clients. Tests substitute a fake or use the real one — the choice belongs to the test.

**Exception:** pure normalizers from already-validated inputs (e.g., `Pagination::fromRequest(int $limit, int $offset)` that just clamps) can stay static. The rule is for parsers of external data.

### Validator (extract from services)

```php
final class EmailValidator
{
    public function isValid(string $email): bool { /* filter_var */ }

    public function assertValid(string $email): void
    {
        if (!$this->isValid($email)) {
            throw new ValidationException('Invalid email format');
        }
    }
}

final class SubscriptionValidator
{
    public function __construct(
        private EmailValidator $email,
        private RepositoryNameValidator $repoName
    ) {}

    public function assertValidSubscription(string $email, string $repository): void
    {
        $this->email->assertValid($email);
        $this->repoName->assertValid($repository);
    }
}
```

Services get the validator injected. Service code becomes orchestration, not regex.

### Narrow Repository interfaces (ISP)

```php
// Fat repository with 14 methods serving 4 different callers — bad.
// Split:

interface SubscriptionRepositoryInterface  // CRUD on subscriptions
{
    public function create(string $email, string $repository): Subscription;
    public function findById(int $id): ?Subscription;
    public function findAll(Pagination $p): SubscriptionPage;
    /* ... */
}

interface SubscriberFinderInterface  // read-side query for dispatch
{
    public function findSubscribersByRepository(string $repository): SubscriberCollection;
}

// One class can implement both:
final class SubscriptionRepository implements
    SubscriptionRepositoryInterface,
    SubscriberFinderInterface
{
    /* ... */
}
```

Naming: avoid `XxxRepositoryInterface` for query-only ports. Use `XxxFinder`, `XxxLookup`, `XxxReader` — the name should reveal the role.

### Pure Fabrication (extract for cohesion)

Symptoms that warrant extraction:
- A class does HTTP + caching + business logic (split into `ApiClient` + `Cache` + orchestrator).
- A method does subject building + HTML + plain-text rendering + SMTP transport (split into `Renderer` + `Mailer`).
- A method does data gathering + Prometheus formatting (split into `Collector` + `Formatter`).

Each extracted class should be testable in isolation without the rest of the stack.

### Collection (only when it owns rules or metadata)

Worth it:
```php
final class SubscriberCollection implements IteratorAggregate, Countable
{
    /** @param list<SubscriberRef> $subscribers */
    public function __construct(private readonly array $subscribers) {}

    /** @param callable(SubscriberRef): bool $hasBeenNotified */
    public function withoutAlreadyNotified(callable $hasBeenNotified): self
    {
        return new self(array_values(array_filter(
            $this->subscribers,
            static fn(SubscriberRef $s): bool => !$hasBeenNotified($s)
        )));
    }
}
```
The collection owns the dedup rule (Information Expert).

Also worth it — pages with metadata:
```php
final class SubscriptionPage
{
    /** @param list<Subscription> $items */
    public function __construct(
        public readonly array $items,
        public readonly Pagination $pagination,
        public readonly int $total
    ) {}

    public function hasNextPage(): bool { /* offset + count(items) < total */ }
}
```

**Not** worth it: a generic `Collection<T>` wrapping an array with no behavior or metadata. That's PHP ceremony.

### Unified exception → status mapping

```php
final class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int { /* match */ }
    public function toGrpcStatus(\Throwable $e): int { /* match */ }
    public function toClientMessage(\Throwable $e): string { /* match */ }
}
```

Inject into both `ErrorHandlerMiddleware` and the gRPC handler. Eliminates two-place drift.

### DI container patterns

```php
// Good: bind interfaces, alias when sharing instances.
SubscriptionRepositoryInterface::class
    => fn($c) => new SubscriptionRepository(/* deps */),
SubscriberFinderInterface::class
    => fn($c) => $c->get(SubscriptionRepositoryInterface::class),
```

```php
// Bad: concrete-class key just to share an instance.
SubscriptionRepository::class
    => fn($c) => new SubscriptionRepository(/* deps */),
SubscriptionRepositoryInterface::class
    => fn($c) => $c->get(SubscriptionRepository::class),  // ← exposes concrete
```

## Code review checklist

When reviewing PHP code or a refactor:

1. **Array-shape annotations** (`array{a: int, b: string}`) flowing across layers → candidate for a DTO.
2. **Validation regex / `filter_var` inside services** → move to a Validator.
3. **Static `from*` methods on DTOs** → flip to factory interface + concrete impl.
4. **One interface serving multiple consumers** with method groups → split via ISP.
5. **Methods over ~40 lines doing multiple jobs** → look for extraction candidates.
6. **DRY: duplicate serialization** (e.g., a private `toPayload` that re-implements `Release::toArray()`) → call the canonical method.
7. **Two places implementing the same exception → status mapping** → unify.
8. **Concrete class registered in DI container** → bind only interfaces.
9. **Naming collisions** (`SubscriptionRepository` vs `SubscriberRepository`) → rename to reveal role.
10. **Test gaps for newly extracted classes** → each Pure Fabrication deserves a focused test.
11. **Behavior regressions in "safe" refactors** — `array_is_list([])` returns true; check empty-input edge cases.
12. **Wire format preserved?** Behat / contract tests still green?

## What to do in user-facing replies

- **Audit requests:** group findings by SOLID/GRASP principle with file:line refs. Don't start editing without confirmation.
- **Refactor approvals:** ask scope before sprawling (full pass vs. partial), and ask whether tests should be updated.
- **Reviews:** be specific — file path, line number, the principle violated, the suggested fix. If everything looks clean, say so; don't invent issues. But re-check honestly before declaring clean.
- **Skill quality bar:** psalm clean (100% type coverage), PSR-12 clean, every test suite green. Use `#[\Override]`. Type everything. No mixed.

## Things to avoid

- Generic `Collection<T>` base class — PHP can't enforce the generic at runtime; you get `mixed[]` in practice.
- Promoting DTOs to value objects "for SOLID" — if the project says DTOs, keep them anemic.
- Replacing every `array` with a typed class. Internal helper arrays in private methods are fine.
- Breaking the public contract (JSON, gRPC reply, behat) during an internal refactor unless the user opted in.
- Static factory methods on DTOs as a "convenience" — they hurt testability.
- Adding ceremony classes that wrap a single field or pass-through method.

## Refactor session pattern

1. **Investigate** — read the affected layers; produce a grouped audit with file:line refs.
2. **Confirm scope** — ask the user: full pass, partial, or minimum viable. Ask whether tests get updated in the same change.
3. **Execute in dependency order** — DTOs first, then factories, then repositories, then services, then boundaries, then tests, then container.
4. **Validate** — psalm + phpunit + phpcs after each major step; behat at the end (boot docker stack only with permission).
5. **Re-review on demand** — be honest in re-reviews; if the dev addressed everything cleanly, say so; if not, name specific files and lines.
