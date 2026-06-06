# github-release-notifier

Slim 4 + PHP-DI app that watches GitHub repos for new releases and emails subscribers. Postgres for persistence, Redis for GitHub-API cache. PHP 8.2+.

**HW7 refactor in progress:** moving to a modular **Clean Architecture + pragmatic DDD** design — bounded contexts under `src/<Context>/<Module>/{Domain,Application,Infrastructure}`, with the **Notification/Email domain being extracted into a separate microservice** (RabbitMQ-only, own DB). This is a **Strangler migration**: the legacy flat code (see "Legacy layout") still exists and is moved context-by-context over Epic B; the deptrac baseline shrinks as it goes.

## Stack

- HTTP: Slim 4 (REST) + Spiral RoadRunner gRPC (`proto/release_notifier.proto`)
- DB: PostgreSQL via PDO; migrations in `migrations/*.sql`
- Cache: Redis via Predis — GitHub-API responses only
- Mail: PHPMailer SMTP
- Cross-service messaging: RabbitMQ via `php-amqplib/php-amqplib` — integration messages to the extracted notification service (introduced over Epics C–D)
- Tests / quality: PHPUnit 10, Psalm (100% types), PHPCS PSR-12, **deptrac** (architecture gate), Behat (acceptance)

## Architecture (target)

Clean layering with the dependency rule pointing **inward** (Domain ← Application ← Infrastructure), organized by bounded context:

- `src/<Context>/<Module>/Domain/` — entities/aggregates, value objects, domain events, ports (interfaces)
- `src/<Context>/<Module>/Application/` — use-cases as CQRS command/query handlers
- `src/<Context>/<Module>/Infrastructure/` — adapters (PDO, Slim controllers, gRPC, RabbitMQ, mail, cache)
- `src/Shared/` — shared kernel (below)
- `apps/` — deployables: `apps/monolith/{http,grpc,scanner}`, `apps/notification/` (the extracted service)

Contexts: **Subscription**, **RepositoryTracking**, **Releases**, **Scanning**, **Notification\Publishing** (monolith publisher) + **Notification\Sending** (the extracted service). Boundaries are enforced by deptrac (`deptrac.yaml`).

### Shared kernel (`src/Shared`)

- `Domain/ValueObject/` — `RepositoryName`, `EmailAddress`, `ReleaseTag`.
- `Domain/Aggregate/AggregateRoot` — base for entities that record domain events (`recordThat` / `pullDomainEvents`).
- `Domain/DomainEvent` — domain-event contract.
- `Domain/Bus/{Command,Query}` — in-house **CQRS** bus contracts (`CommandBus`/`QueryBus` + handlers); adapters in `Infrastructure/Bus/InMemory{Command,Query}Bus`. **No Symfony Messenger.**
- `Infrastructure/Event/` — synchronous in-memory **PSR-14** plane (`InMemoryEventDispatcher` + `ListenerProvider`) for in-process domain events.

### Two event planes

- **In-process domain events → PSR-14**, synchronous, in-memory (e.g. `NewReleaseDetected`, aggregate events). Listener exceptions **propagate** — a publish failure aborts marker advancement, which keeps the flow **outbox-free**.
- **Cross-service → RabbitMQ integration messages** (e.g. `SendReleaseEmail`, an integration *command* the monolith publishes after it has resolved the recipients).

### Legacy layout (being migrated — Epic B)

Old flat dirs still in place until moved into their context homes: `src/Domain` (anemic DTOs), `src/Service`, `src/Repository`, `src/Controller`, `src/Middleware`, `src/Grpc`, `src/GitHub`, `src/Notifier`, `src/Cache`, `src/Validation`, `src/Factory`, `src/Config`, `src/Exception`, `src/Metrics`, `src/Health`. `config/container.php` — single DI definitions file. `tests/` mirrors `src/`.

## Conventions

- **`final readonly class`** for stateless services, repositories, controllers, middleware, bus adapters. Skip only when mutable state is required (see `SafeGitHubCacheDecorator`; and `AggregateRoot`'s event buffer).
- **Entities are aggregate roots** extending `Shared\Domain\Aggregate\AggregateRoot` and recording domain events. **Value-object snapshots stay anemic + readonly** (e.g. `Release` — no identity/lifecycle). Anemic DTOs are constructed through a `*FactoryInterface` — no `from*` static methods.
- **Use-cases are CQRS handlers** dispatched through the in-house `CommandBus`/`QueryBus`; thin drivers (controllers, gRPC, CLI scanner) build a Command/Query and hand it to the bus.
- **Validators are injected classes**, never inline `filter_var` / regex inside services.
- **Per-consumer ISP** for repositories/ports (`*Reader`, `*Writer`, `*Registrar`, `*Source`, `*Finder`). Split read/write, or one class implementing several narrow interfaces (see `SubscriptionRepository`).
- **`#[\Override]`** on every interface implementation method.
- **DI: bind interfaces only.** To share one instance across two interfaces, alias the second to the first. Never use a concrete class as a DI key just to share an instance.
- **One exception → status mapping** in `ExceptionStatusMap`, used by both `ErrorHandlerMiddleware` and `Grpc\ReleaseNotifierService`.
- **Rate limiting and control-flow exceptions** are caught at the orchestration layer (Scanning), not inside the unit method.
- **Architecture boundaries enforced by deptrac.** Domain depends on nothing outward; no cross-context Infrastructure dependencies. Grant legitimate cross-context *port* edges explicitly in `deptrac.yaml`; the baseline only shrinks — never grow it without justification.
- **Wire-format protection.** JSON shape, gRPC reply, Behat assertions are public contract. Internal refactors must preserve them unless the user opts in.

## Quality gates

All must pass before claiming a task done:

```bash
composer lint                                   # PHPCS PSR-12 + deptrac (architecture rules)
./vendor/bin/phpunit --no-coverage --testsuite Unit   # env-independent unit gate
composer psalm                                  # 100% type coverage (errorLevel 1)
```

The full `./vendor/bin/phpunit` run also exercises the **Integration** suite, which needs the docker stack (Postgres/Redis); without it, use `--testsuite Unit`. Behat acceptance also needs the stack — ask before booting it.

## Refactor / review workflow

Triggered via the `php-refactor-workflow` skill — it owns the audit-first procedure, review checklist, and dependency-ordered execution pattern.

The HW7 modular refactor is driven by **BMad-METHOD** (planning + per-story create-story → dev-story → code-review); its artifacts live under `_bmad-output/` (gitignored). Keep the LikeC4 model in sync via `likec4-architecture-sync` when changing components/wiring.
