---
name: implementing-ddd-architecture
description: Design and implement DDD patterns (entities, value objects, aggregates, CQRS) for the github-release-notifier monolith and the extracted notification service. Use when creating new domain objects, implementing bounded contexts, designing repository/port interfaces, or learning proper layer separation. For fixing existing deptrac violations, use the deptrac-fixer skill instead.
---

# Implementing DDD Architecture

## Context (Input)

- Creating new entities, value objects, or aggregates
- Implementing bounded contexts or modules
- Designing repository / port interfaces and implementations
- Learning proper layer separation (Domain/Application/Infrastructure)
- Need to understand the CQRS pattern (Commands, Queries, Handlers, Events)
- Code review for architectural compliance

## Task (Function)

Design and implement rich domain models following pragmatic (CodelyTV-style) DDD, hexagonal architecture, and CQRS patterns.

**Success Criteria**:

- Domain entities remain framework-agnostic (no Slim, PDO, Predis, PHPMailer, php-amqplib, gRPC imports)
- Business logic in the Domain layer, not in Application handlers
- `make deptrac` shows zero violations beyond the (only-shrinking) baseline
- Repository / port interfaces in Domain, adapters in Infrastructure

---

## Core Principle

**Rich Domain Models, Not Anemic**

Business logic belongs in the Domain layer. The Application layer orchestrates, the Domain executes. Self-validating value objects own input validation — constructing the VO *is* the validation.

---

## Layer Dependency Rules

The dependency rule points **inward**, organized by bounded context:

```
Domain ─────────────────> (NO dependencies outward - pure PHP)
           ▲
           │
Application ──────────> Domain (its own context + granted cross-context Domain ports) + Shared
           ▲
           │
Infrastructure ───────> Domain + Application + Shared
```

**Allowed Dependencies**:

| Layer              | Can Import                                                                 |
| ------------------ | ------------------------------------------------------------------------- |
| **Domain**         | ❌ Nothing outward (pure PHP, SPL, Shared\Domain only)                    |
| **Application**    | ✅ Same-context Domain, Shared\Domain, Shared\Application, granted cross-context Domain *ports* |
| **Infrastructure** | ✅ Same-context Domain + Application, Shared.*, the framework (Slim, PDO, Predis, PHPMailer, php-amqplib, gRPC) |

> No cross-context **Infrastructure** dependencies are ever allowed. Legitimate cross-context edges run through **Domain ports** and must be granted explicitly in `deptrac.yaml`. The baseline only shrinks.

**See**: [DIRECTORY-STRUCTURE.md](DIRECTORY-STRUCTURE.md) for the complete file-placement guide.

---

## Critical Rules

### 1. Domain Layer Purity

❌ **FORBIDDEN in Domain**:

- Slim / PSR-7 HTTP types
- PDO / SQL / Predis / Redis
- PHPMailer, php-amqplib (RabbitMQ), Spiral RoadRunner gRPC
- Any framework-specific or infrastructure code

✅ **ALLOWED in Domain**:

- Pure PHP, SPL (`\DateTimeImmutable`, etc.)
- Shared kernel value objects (`App\Shared\Domain\ValueObject\*`)
- Domain interfaces (ports), domain events, domain exceptions

### 2. Rich Domain Models

❌ **BAD (Anemic)**:

```php
final class RepositoryStatus {
    public function setLastSeenTag(string $tag): void {
        $this->lastSeenTag = $tag;  // No invariant, no event!
    }
}
```

✅ **GOOD (Rich)**:

```php
final class RepositoryStatus extends AggregateRoot {
    public function markReleaseSeen(string $tag): void {
        $this->lastSeenTag = $tag;
        $this->recordThat(new ReleaseSeenAdvanced($this->fullName, $tag, new \DateTimeImmutable()));
    }
}
```

### 3. Validation Pattern

In this codebase, **input validation lives in the self-validating Shared value objects** —
`EmailAddress`, `RepositoryName`, `ReleaseTag`. Constructing the VO *is* the validation.

❌ **BAD**: inline `filter_var` / regex in a service or handler

```php
final readonly class SubscribeCommandHandler implements CommandHandler {
    public function __invoke(Command $command): void {
        if (filter_var($command->email, FILTER_VALIDATE_EMAIL) === false) { // ❌ in a handler!
            throw new \InvalidArgumentException('bad email');
        }
    }
}
```

✅ **GOOD**: construct the self-validating VO; let it reject

```php
final readonly class SubscribeCommandHandler implements CommandHandler {
    public function __invoke(Command $command): void {
        // Constructing the VO IS the validation. A bad value throws
        // Shared\Domain\Exception\InvalidArgumentException, mapped to
        // 400 / INVALID_ARGUMENT by ExceptionStatusMap.
        $email = new EmailAddress($command->email);
        $repository = new RepositoryName($command->repository);
        // ...
    }
}
```

We do **NOT** use Symfony Validator, annotation/attribute-based validation, or YAML
validation configs. We do **NOT** reintroduce standalone `App\Validation\*` validator
classes — those were absorbed into the VOs. Transport-level shape checks (missing
fields, non-JSON body) stay in controllers as `ValidationException`.

**See**: [REFERENCE.md](REFERENCE.md) for the complete validation patterns.

---

## CQRS Pattern Quick Start

Use-cases are CQRS handlers dispatched through the **in-house** `CommandBus` / `QueryBus`
(`App\Shared\Domain\Bus\*`). We do **NOT** use Symfony Messenger. Thin drivers
(Slim controllers, gRPC handlers, the CLI scanner) build a Command/Query and hand it
to the bus.

### Commands (Write Operations)

```php
// src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommand.php
final readonly class SubscribeCommand implements Command
{
    public function __construct(
        public string $email,
        public string $repository
    ) {}
}
```

### Command Handlers

```php
// src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommandHandler.php
/** @implements CommandHandler<SubscribeCommand> */
final readonly class SubscribeCommandHandler implements CommandHandler
{
    #[\Override]
    public function __invoke(Command $command): void
    {
        // Minimal orchestration: build VOs, call the aggregate, persist, dispatch events.
        $email = new EmailAddress($command->email);
        $repository = new RepositoryName($command->repository);
        // ...
    }
}
```

### Queries (Read Operations)

```php
/** @implements QueryHandler<FetchLatestReleaseQuery, FetchLatestReleaseResponse> */
final readonly class FetchLatestReleaseHandler implements QueryHandler
{
    #[\Override]
    public function __invoke(Query $query): Response
    {
        return new FetchLatestReleaseResponse(
            $this->source->getLatestRelease(new RepositoryName($query->repository))
        );
    }
}
```

**See**: [REFERENCE.md](REFERENCE.md) for the complete CQRS patterns and the generics rationale.

---

## Repository / Port Pattern

Ports are declared in **Domain**, implemented in **Infrastructure**. Apply per-consumer
ISP: split read/write with narrow suffixed interfaces (`*Reader`, `*Writer`,
`*Registrar`, `*Source`, `*Finder`); one adapter class may implement several.

### Interface (Domain Layer)

```php
// src/Subscription/Subscriptions/Domain/SubscriptionRepository.php
interface SubscriptionRepository
{
    public function create(Subscription $subscription): Subscription;
    public function findById(int $id): ?Subscription;
}
```

### Implementation (Infrastructure Layer)

```php
// src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionRepository.php
final readonly class PdoSubscriptionRepository implements
    SubscriptionRepository,
    SubscriberFinder,        // narrow read port for the Publishing context
    SubscriptionCountPort    // narrow read port for metrics
{
    public function __construct(
        private PDO $pdo,
        private SubscriptionFactoryInterface $subscriptionFactory,
        private SubscriberRefFactoryInterface $subscriberRefFactory
    ) {}

    #[\Override]
    public function create(Subscription $subscription): Subscription { /* raw SQL */ }
}
```

**Wire in `config/container.php` (bind interfaces only; alias to share an instance)**:

```php
SubscriptionRepository::class => fn (ContainerInterface $c) => /* build PdoSubscriptionRepository */,
// One instance, two ports: alias the second interface to the first.
SubscriberFinder::class => DI\get(SubscriptionRepository::class),
```

> DI rule: **bind interfaces only**. Never use a concrete class as a DI key just to
> share an instance — alias the second interface to the first.

---

## Domain Events Pattern

Two event planes:

- **In-process domain events → synchronous PSR-14**, in-memory. Listener exceptions
  **propagate** (a publish failure aborts marker advancement — the flow is outbox-free).
- **Cross-service → RabbitMQ integration messages** (e.g. `SendReleaseEmail`), a versioned
  schema with idempotency metadata — never a `DomainEvent`.

### Recording Events in Aggregates

```php
// Aggregates extend Shared\Domain\Aggregate\AggregateRoot (recordThat / pullDomainEvents)
final class Subscription extends AggregateRoot
{
    public static function subscribe(EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        $subscription = new self(null, $email, $repository, $createdAt);
        $subscription->recordThat(new SubscriptionCreated((string) $email, (string) $repository, new \DateTimeImmutable()));
        return $subscription;
    }
}
```

### Listeners (Infrastructure, wired through PSR-14)

```php
// src/Subscription/Subscriptions/Infrastructure/Listener/WhenSubscriptionCreatedThenLog.php
final readonly class WhenSubscriptionCreatedThenLog
{
    public function __invoke(SubscriptionCreated $event): void
    {
        // React to the in-process domain event (log, metrics, publish a message, ...).
    }
}
```

**See**: [REFERENCE.md](REFERENCE.md) for the complete event-driven patterns and the two-plane rule.

---

## Quick Start Workflows

### Creating a New Entity

1. **Create the Entity / Aggregate** in `<Context>/<Module>/Domain/` (extends `AggregateRoot`)
2. **Reuse or create Value Objects** (Shared VOs first; new VOs in Domain)
3. **Create the Port interface(s)** in `<Context>/<Module>/Domain/` (per-consumer ISP)
4. **Create the adapter** in `<Context>/<Module>/Infrastructure/` (PDO + raw SQL migration)
5. **Create the Command/Query** in `<Context>/<Module>/Application/<UseCase>/`
6. **Create the Handler** in the same use-case folder
7. **Wire DI** in `config/container.php` (interfaces only)
8. **Verify**: `make deptrac` shows zero new violations

**See**: [examples/](examples/) for complete working examples.

### Fixing Deptrac Violations

**If** `make deptrac` shows violations:

**Use**: the [deptrac-fixer](../deptrac-fixer/SKILL.md) skill for step-by-step fix patterns.

---

## Constraints (Parameters)

### NEVER

- Add framework / infrastructure imports to the Domain layer
- Put business logic in Application handlers (handlers orchestrate only)
- Create anemic domain models (getters/setters only)
- Modify `deptrac.yaml` / `deptrac.baseline.yaml` to *allow* a violation (the baseline only shrinks)
- Skip validation — construct the self-validating Shared VO instead
- Use public setters in entities
- Inline `filter_var` / regex validation in services or handlers
- Reintroduce standalone `App\Validation\*` validator classes (absorbed into the VOs)
- Add `from*` static constructors to anemic DTO snapshots — build them via a `*FactoryInterface`
- Use a concrete class as a DI key just to share an instance — alias the interface
- Use Symfony Messenger, Symfony Validator, Doctrine, MongoDB, or API Platform — we do **NOT** use them

### ALWAYS

- Keep the Domain layer pure (no framework dependencies)
- Put business logic in Domain entities/aggregates
- Use the self-validating Shared VOs (`EmailAddress`, `RepositoryName`, `ReleaseTag`) for input validation
- Use named constructors (`fromString()`) only on self-validating VOs — not on anemic DTO snapshots
- Build anemic readonly DTO snapshots (e.g. a `Release`/`ReleaseSnapshot`) via a `*FactoryInterface`
- Put `#[\Override]` on every interface-implementation method
- Declare ports in Domain, implement adapters in Infrastructure (per-consumer ISP)
- Dispatch use-cases through the in-house `CommandBus` / `QueryBus`
- Record domain events for state changes; let PSR-14 listener exceptions propagate
- Map every domain exception to a status in `ExceptionStatusMap` (one source of truth)
- Verify with `make deptrac` after changes

---

## Format (Output)

### Expected Directory Structure

```
src/<Context>/<Module>/
├── Domain/
│   ├── <Entity>.php                 # Aggregate root, pure PHP, extends AggregateRoot
│   ├── <Snapshot>.php               # Anemic readonly DTO (no identity/lifecycle)
│   ├── <Event>.php                  # Domain event (implements Shared\Domain\DomainEvent)
│   ├── <Port>Reader.php / <Port>Writer.php / <Port>Finder.php   # ports (ISP)
│   └── <SpecificError>Exception.php
├── Application/
│   └── <UseCase>/
│       ├── <Action>Command.php      # or <Action>Query.php + <Action>Response.php
│       └── <Action>CommandHandler.php
└── Infrastructure/
    ├── Persistence/Pdo<Entity>Repository.php
    ├── Factory/<Snapshot>Factory.php + <Snapshot>FactoryInterface.php
    └── Listener/When<Event>Then<Action>.php
```

### Expected Deptrac Output

```
[OK] No violations found beyond the baseline.
```

---

## Verification Checklist

After implementing DDD patterns:

- [ ] Domain entities have no framework imports
- [ ] Business logic in the Domain layer, not Application
- [ ] Self-validating Shared VOs used for input validation
- [ ] Port interfaces in Domain, adapters in Infrastructure (per-consumer ISP)
- [ ] Commands implement `Command`; Queries implement `Query`; Responses implement `Response`
- [ ] Handlers implement `CommandHandler` / `QueryHandler` with the `@implements` generic
- [ ] `#[\Override]` on every interface-implementation method
- [ ] Domain events recorded in aggregates; listeners wired through PSR-14
- [ ] DI binds interfaces only (aliases to share instances)
- [ ] `make deptrac` shows zero new violations
- [ ] `make test` passes (Unit)
- [ ] `make ci` passes (ends with "✅ CI checks successfully passed!")

---

## Related Skills

- [deptrac-fixer](../deptrac-fixer/SKILL.md) — Fix architectural violations
- [code-organization](../code-organization/SKILL.md) — Structure, naming, file placement
- [code-review](../code-review/SKILL.md) — Review for architectural compliance
- [testing-workflow](../testing-workflow/SKILL.md) — Unit / Integration / Behat tests
- [quality-standards](../quality-standards/SKILL.md) — Overview of the protected quality gates

---

## Reference Documentation

For detailed patterns, workflows, and examples:

- **[REFERENCE.md](REFERENCE.md)** — Complete DDD workflows and patterns
- **[DIRECTORY-STRUCTURE.md](DIRECTORY-STRUCTURE.md)** — File-placement guide (CodelyTV style)
- **[examples/](examples/)** — Complete working examples:
  - `01-entity-example.php` — Aggregate root with domain events
  - `02-value-object-examples.php` — Choosing the right value type
  - `03-cqrs-pattern-example.php` — Command/Query handlers + the in-house bus
  - `04-fixing-deptrac-violations.php` — Before/after fixes

---

## Anti-Patterns to Avoid

### ❌ Business Logic in Handlers

```php
// ❌ BAD: invariant + validation in the handler
final readonly class SubscribeCommandHandler implements CommandHandler {
    public function __invoke(Command $command): void {
        if (strlen($command->email) < 3) { // ❌ validation in handler!
            throw new \RuntimeException();
        }
    }
}
```

### ❌ Framework / Infrastructure in Domain

```php
// ❌ BAD: PDO in a Domain class
namespace App\Subscription\Subscriptions\Domain;
use PDO; // ❌ infrastructure coupling in Domain!
```

### ❌ Anemic Domain Models

```php
// ❌ BAD: just getters/setters, no invariant, no event
final class RepositoryStatus {
    public function setLastSeenTag(string $tag): void { $this->lastSeenTag = $tag; }
}
```

### ✅ GOOD Patterns

- Self-validating VOs enforce input invariants
- Domain methods express business operations and record events
- Handlers orchestrate, the Domain executes
- Ports in Domain, adapters in Infrastructure; DI binds interfaces only

---

## CodelyTV Architecture Pattern

This project follows CodelyTV's pragmatic hexagonal/DDD patterns:

- **Directory structure**: Bounded Context → Module → Layer → Use-case folder
- **Naming conventions**: Explicit suffixes (`Command`, `Handler`, `Query`, `Response`, `Reader`, `Writer`, `Finder`)
- **Layer isolation**: deptrac enforces boundaries; cross-context edges only via Domain ports
- **CQRS**: Commands for writes, Queries for reads, dispatched on the in-house bus
- **Event-driven**: in-process PSR-14 domain events; RabbitMQ for cross-service integration messages

**See**: [DIRECTORY-STRUCTURE.md](DIRECTORY-STRUCTURE.md) for the complete hierarchy.
