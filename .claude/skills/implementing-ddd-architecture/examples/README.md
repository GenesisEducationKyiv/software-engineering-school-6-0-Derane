# DDD Architecture Examples

This directory contains code examples demonstrating the Domain-Driven Design (DDD)
and hexagonal/Clean Architecture patterns used in **github-release-notifier**
(Slim 4 + PHP-DI, PHP 8.2+, PostgreSQL via PDO, in-house CQRS bus). Every example
is namespaced under `Example\*` so it never collides with production code, while
mirroring real classes from `src/`.

## Files Overview

### 01-entity-example.php

**Rich Domain Aggregate (NOT anemic)**

Mirrors `App\RepositoryTracking\Repositories\Domain\RepositoryStatus`.

Demonstrates:

- Rich domain model: business logic in intention-revealing methods (not setters)
- Aggregate root pattern with domain events (`recordThat` / `pullDomainEvents`)
- Named constructors (`existing()`, `reconstitute()`); reconstitution is event-free
- Invariant enforcement (idempotent marker advance) inside the aggregate
- NO dependencies outward (pure PHP + `App\Shared\Domain` only)

Key Concepts:

- `extends App\Shared\Domain\Aggregate\AggregateRoot` for the event buffer
- Business methods (not setters): `markReleaseSeen()`, `markChecked()`
- Domain events: `ReleaseSeenAdvanced`, `RepositoryChecked` (implement `Shared\Domain\DomainEvent`)
- Validated input via the `ReleaseTag` value object

### 02-value-object-examples.php

**Choosing the right value type (pragmatic)**

**IMPORTANT**: this project has THREE kinds of value — pick the lightest one that fits.

Demonstrates:

- ❌ ANTI-PATTERNS: inline `filter_var`/regex instead of the VO; `from*` on an anemic DTO
- ✅ Self-validating Value Object: `RepositoryName`, `ReleaseTag` (validation lives here)
- ✅ Anemic readonly DTO snapshot: `Release` (pure data, built via a `*FactoryInterface`)
- ✅ Aggregate / Entity: identity + lifecycle (see `01-entity-example.php`)
- ✅ DECISION TREE for which type to reach for

Key Principles:

- **Input validation lives in the self-validating Shared VOs** — constructing the VO IS the validation
- **No** framework validator, annotations, or YAML validation configs in this project
- **Anemic snapshots are pure data**, assembled via factories (no `from*` on DTOs)
- `fromString()` is for self-validating VOs only — never for anemic DTOs

### 03-cqrs-pattern-example.php

**CQRS with the in-house bus (NOT Symfony Messenger)**

Demonstrates the complete flow:

- Commands (writes): `SubscribeCommand` → `SubscribeCommandHandler`
- Queries (reads): `FetchLatestReleaseQuery` → `FetchLatestReleaseHandler` → `FetchLatestReleaseResponse`
- Thin driver: a Slim `SubscriptionController` builds a Command and hands it to the `CommandBus`
- Port in Domain (`SubscriptionRepository`), adapter in Infrastructure (`PdoSubscriptionRepository`, raw SQL)
- Full path: HTTP body → Command → bus → handler → VOs → aggregate → port → domain events

Key Concepts:

- Commands implement `Command`; Queries implement `Query`; results implement `Response`
- Handlers implement `CommandHandler<T>` / `QueryHandler<T, R>` with the `@implements` generic (Psalm)
- `#[\Override]` on every `__invoke`
- Handlers orchestrate; the aggregate holds business logic; VOs validate; ports persist
- Domain events drained onto the synchronous PSR-14 plane after persistence
- DI binds interfaces only; alias a second interface to share one instance

### 04-fixing-deptrac-violations.php

**Common deptrac violations and fixes (PRAGMATIC)**

**IMPORTANT**: uses THIS project's patterns (self-validating VOs, factories, ports, the in-house bus).

Demonstrates **BEFORE / AFTER** code for:

1. **Domain doing inline input validation** → use the self-validating Shared VO
2. **Domain → PDO** → keep the snapshot pure; SQL in the Infrastructure adapter
3. **Cross-context Infrastructure dependency** → depend on the other context's Domain **port**
4. **Infrastructure → concrete Application handler** → use the `CommandBus` or a domain event
5. **`from*` static constructor on an anemic DTO** → build via a `*FactoryInterface`
6. **Anemic domain model** → move invariants + events into the aggregate

Step-by-Step Workflow:

- Run `make deptrac`
- Read the violation message
- Identify the layer/dependency problem
- Plan the refactor (move code; never touch deptrac config)
- Fix the code
- Verify with `make deptrac`, then `make test` / `make psalm` / `make ci`

## How to Use These Examples

### For LLM Agents

When working on a task:

1. **Creating an aggregate/entity?** → Reference `01-entity-example.php`
   - Extend `AggregateRoot`; use named constructors
   - Business logic in methods, not setters; record domain events

2. **Choosing a value type / need validation?** → Reference `02-value-object-examples.php`
   - Invariant-bearing value → self-validating VO (validation lives there)
   - Pure data carrier → anemic readonly DTO via a `*FactoryInterface`
   - Identity + lifecycle → aggregate

3. **Implementing a use case?** → Reference `03-cqrs-pattern-example.php`
   - Create a Command/Query, then a Handler with the `@implements` generic
   - Construct VOs, delegate to the aggregate, persist via the port, dispatch events

4. **Deptrac violation?** → Reference `04-fixing-deptrac-violations.php`
   - Find the similar violation, apply the fix pattern
   - NEVER change `deptrac.yaml` / `deptrac.baseline.yaml`

### For Developers

These examples serve as templates, architectural reference, onboarding material,
and standards documentation that reflect the actual patterns in `src/`.

## Layer Dependency Rules

```
Infrastructure → Application → Domain
        ↓             ↓           ↓
   framework /     use cases   business logic
   adapters       (handlers)   (aggregates, VOs, events, ports)
```

### Domain Layer

- **Allowed**: pure PHP, SPL, `App\Shared\Domain\*`
- **Forbidden**: Slim, PDO, Predis, PHPMailer, php-amqplib, gRPC — ANY framework/infra

### Application Layer

- **Allowed**: own-context Domain, `Shared\Domain`, `Shared\Application`, granted cross-context Domain **ports**
- **Forbidden**: business logic (delegate to Domain); another context's adapters

### Infrastructure Layer

- **Allowed**: own-context Domain + Application, `Shared.*`, the framework
- **Forbidden**: business logic; cross-context Infrastructure → Infrastructure edges

## Quick Checklist

Before committing code, ensure:

- [ ] `make deptrac` passes with zero new violations (baseline only shrinks)
- [ ] Domain has NO framework/infrastructure imports
- [ ] Business logic is in aggregates, NOT in handlers
- [ ] Handlers only orchestrate (construct VOs, delegate, persist, dispatch events)
- [ ] **Input validation lives in the self-validating Shared VOs** (no inline `filter_var`/regex)
- [ ] **Anemic snapshots built via a `*FactoryInterface`** (no `from*` on DTOs)
- [ ] Commands implement `Command`; Queries implement `Query`; results implement `Response`
- [ ] Handlers implement `CommandHandler` / `QueryHandler` with the `@implements` generic
- [ ] `#[\Override]` on every interface-implementation method
- [ ] Port interfaces in Domain, adapters in Infrastructure (per-consumer ISP)
- [ ] DI binds interfaces only (aliases share instances)
- [ ] Aggregates extend `AggregateRoot` and use `recordThat()` for events

## Additional Resources

- **Project Documentation**: `CLAUDE.md` (project root) — stack, conventions, quality gates
- **Architecture Guidelines**: `.claude/skills/implementing-ddd-architecture/SKILL.md`
- **Deptrac Config**: `deptrac.yaml` + `deptrac.baseline.yaml` (project root)
- **Related Skills**: `deptrac-fixer`, `code-organization`, `code-review`, `testing-workflow`, `quality-standards`, `ci-workflow`

## Common Patterns Summary

| Pattern                   | Domain    | Application  | Infrastructure  |
| ------------------------- | --------- | ------------ | --------------- |
| Aggregates / Entities     | ✅ Define | ❌           | ❌              |
| Self-validating VOs       | ✅ Define | ❌           | ❌              |
| Anemic readonly snapshots | ✅ Define | ❌           | 🏭 Build (factory) |
| Port interfaces           | ✅ Define | ❌           | ❌              |
| PDO repository adapters   | ❌        | ❌           | ✅ Implement    |
| Commands / Queries        | ❌        | ✅ Define    | ❌              |
| Command / Query Handlers  | ❌        | ✅ Implement | ❌              |
| Responses                 | ❌        | ✅ Define    | ❌              |
| Domain Events             | ✅ Define | ❌           | ❌              |
| PSR-14 Listeners          | ❌        | ❌           | ✅ Implement    |
| Slim controllers / gRPC / CLI drivers | ❌ | ❌      | ✅ Implement    |
| RabbitMQ publishers/consumers | ❌    | ❌           | ✅ Implement    |
| Bus adapters (InMemory*)  | ❌        | ❌           | ✅ Implement    |

---

**Remember**: these examples are living documentation. They reflect the actual patterns
used in this codebase — follow them closely to maintain architectural consistency.
