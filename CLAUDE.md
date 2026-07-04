# github-release-notifier

Slim 4 + PHP-DI app that watches GitHub repos for new releases and emails subscribers. Postgres for persistence, Redis for GitHub-API cache. PHP 8.4 monolith + PHP 8.2 notification service.

**HW7 refactor — essentially complete:** modular **Clean Architecture + pragmatic DDD** — bounded contexts under `src/<Context>/<Module>/{Domain,Application,Infrastructure}`, with the **Notification/Sending domain extracted into a separate microservice** (RabbitMQ-only integration, own DB, own deptrac). The Strangler migration has drained: legacy remainder is only `src/Controller` + `src/Grpc` + `src/Migration` (see "Legacy layout") and **the deptrac baseline is empty** — keep it that way. HW9 added the orchestrated **enrollment saga** (`src/Saga/Enrollment`, ADR-0003) that confirms subscriptions via a welcome email, with three interchangeable welcome-email transports (`WELCOME_EMAIL_TRANSPORT=rabbit|rest|grpc`, ADR-0004).

## Stack

- HTTP: Slim 4 (REST) + Spiral RoadRunner gRPC (`proto/release_notifier.proto`)
- DB: PostgreSQL via PDO; migrations in `migrations/*.sql`
- Cache: Redis via Predis — GitHub-API responses only
- Mail: PHPMailer SMTP
- Cross-service messaging: RabbitMQ via `php-amqplib/php-amqplib` — three versioned integration messages (`SendReleaseEmail/v1`, `SendWelcomeEmail/v1`, `WelcomeEmailOutcome/v1`; JSON schemas in `contracts/`). The only artifacts shared between the codebases are those schemas plus the welcome-email gRPC contract (`proto/notification/welcome/v1/welcome.proto` + buf-generated `gen/` stubs — both composer.json files autoload the same repo-root `gen/` tree)
- Tests / quality: PHPUnit 10, Psalm (100% types), PHPCS PSR-12, **deptrac** (architecture gate), Behat (acceptance)

## Architecture (target)

Clean layering with the dependency rule pointing **inward** (Domain ← Application ← Infrastructure), organized by bounded context:

- `src/<Context>/<Module>/Domain/` — entities/aggregates, value objects, domain events, ports (interfaces)
- `src/<Context>/<Module>/Application/` — use-cases as CQRS command/query handlers
- `src/<Context>/<Module>/Infrastructure/` — adapters (PDO, Slim controllers, gRPC, RabbitMQ, mail, cache)
- `src/Shared/` — shared kernel (below)
- `apps/` — deployables. `apps/notification/` is the fully extracted service (own composer.json, src, tests, `deptrac.yaml`); its container runs three co-located processes: the Rabbit consumer loop, a `php -S` HTTP surface on `:8081` (health/metrics + sync `POST /internal/welcome-emails`), and a RoadRunner gRPC server on `:9002`. `apps/monolith/{http,grpc,scanner}` are placeholder stubs only — the monolith's real entrypoints are still `public/index.php`, `bin/grpc.php`, `bin/scanner.php` and `bin/saga-worker.php` (docker-compose runs the scanner and saga-worker directly; the gRPC worker is spawned by `rr serve -c .rr.grpc.yaml`, and FrankenPHP/Caddyfile serves `public/index.php`); moving them under `apps/monolith` is deferred work.

Contexts: **Subscription**, **RepositoryTracking** (no use-case layer — Domain is ports + the `RepositoryStatus` read-model, Infrastructure the PDO adapters), **Releases**, **Scanning** (no Domain layer), **Saga\Enrollment** (HW9 orchestrator), **Notification\Publishing** (monolith publisher) + **Notification\Sending** (the extracted service). Boundaries are enforced by deptrac (`deptrac.yaml`); the code-view diagrams in `docs/architecture/layers.c4` mirror the rulesets.

### Shared kernel (`src/Shared`)

- `Domain/ValueObject/` — `RepositoryName`, `EmailAddress`, `ReleaseTag`, `SagaId`, `Pagination`.
- `Domain/Aggregate/AggregateRoot` — base for entities that record domain events (`recordThat` / `pullDomainEvents`).
- `Domain/DomainEvent` — domain-event contract.
- `Domain/Bus/{Command,Query}` — in-house **CQRS** bus contracts (`CommandBus`/`QueryBus` + handlers); adapters in `Infrastructure/Bus/InMemory{Command,Query}Bus`. **No Symfony Messenger.**
- `Infrastructure/Event/` — synchronous in-memory **PSR-14** plane (`InMemoryEventDispatcher` + `ListenerProvider`) for in-process domain events.

### Two event planes

- **In-process domain events → PSR-14**, synchronous, in-memory (e.g. `NewReleaseDetected`, aggregate events). Listener exceptions **propagate** — a publish failure aborts marker advancement, which keeps the **release flow outbox-free**.
- **Cross-service → RabbitMQ integration messages** (e.g. `SendReleaseEmail`, an integration *command* the monolith publishes after it has resolved the recipients).
- **Exception:** the enrollment saga's welcome-email path is NOT outbox-free — the saga-worker runs an outbox-style relay over `enrollment_sagas` rows (publisher confirms, `WelcomeEmailOutcome` reply consumer, timeout sweeper) so every saga converges to confirmed/cancelled.

### Legacy layout (Strangler remainder)

Epic B has drained the flat layout; only three thin-driver dirs remain outside the contexts: `src/Controller` (`HealthController`, `MetricsController`), `src/Grpc` (`ReleaseNotifierService`), `src/Migration` (`Migrator`) — collected as `Legacy.Infrastructure` in deptrac. `config/container.php` — single DI definitions file. `tests/` mirrors `src/`.

## Conventions

- **`final readonly class`** for stateless services, repositories, controllers, middleware, bus adapters. Skip only when mutable state is required (see `SafeGitHubCacheDecorator`; and `AggregateRoot`'s event buffer).
- **Entities are aggregate roots** extending `Shared\Domain\Aggregate\AggregateRoot` and recording domain events. **Value-object snapshots stay anemic + readonly** (e.g. `Release` — no identity/lifecycle). Anemic DTOs are constructed through a `*FactoryInterface` — no `from*` static methods. **Value objects** (e.g. `RepositoryName`, `EmailAddress`) may use named constructors like `fromString()` — that idiom is for self-validating VOs, not for the anemic DTOs the `from*` ban targets.
- **Use-cases are CQRS handlers** dispatched through the in-house `CommandBus`/`QueryBus`; thin drivers (controllers, gRPC, CLI scanner) build a Command/Query and hand it to the bus.
- **Input validation lives in the self-validating Shared VOs** (`EmailAddress`, `RepositoryName`, `ReleaseTag`) — constructing the VO *is* the validation; `Shared\Domain\Exception\InvalidArgumentException` maps to 400/INVALID_ARGUMENT in `ExceptionStatusMap`. Never inline `filter_var` / regex inside services, and don't reintroduce standalone validator classes (the legacy `App\Validation\*` ones were absorbed into the VOs). Transport-level shape checks (missing fields, non-JSON body) stay in controllers as `ValidationException`.
- **Per-consumer ISP** for repositories/ports (`*Reader`, `*Writer`, `*Registrar`, `*Source`, `*Finder`). Split read/write, or one class implementing several narrow interfaces (see `SubscriptionRepository`).
- **`#[\Override]`** on every interface implementation method.
- **DI: bind interfaces only.** To share one instance across two interfaces, alias the second to the first. Never use a concrete class as a DI key just to share an instance.
- **One exception → status mapping** in `ExceptionStatusMap`, used by both `ErrorHandlerMiddleware` and `Grpc\ReleaseNotifierService`.
- **Rate limiting and control-flow exceptions** are caught at the orchestration layer (Scanning), not inside the unit method.
- **Architecture boundaries enforced by deptrac.** Domain depends on nothing outward; no cross-context Infrastructure dependencies. Grant legitimate cross-context *port* edges explicitly in `deptrac.yaml`; **the baseline is empty (`skip_violations: {}`) — never add to it.** When a grant changes, update `docs/architecture/layers.c4` in the same PR (it mirrors the ruleset edge-for-edge).
- **Wire-format protection.** JSON shape, gRPC reply, Behat assertions are public contract. Internal refactors must preserve them unless the user opts in.

## Quality gates

All must pass before claiming a task done:

```bash
composer lint                                   # PHPCS PSR-12 + deptrac (architecture rules)
./vendor/bin/phpunit --no-coverage --testsuite Unit   # env-independent unit gate
composer psalm                                  # 100% type coverage (errorLevel 1)
```

The full `./vendor/bin/phpunit` run also exercises the **Integration** suite, which needs the docker stack (Postgres/Redis); without it, use `--testsuite Unit`. Behat acceptance also needs the stack — ask before booting it.

The notification service has the same gates run from its own root: `cd apps/notification && composer lint && ./vendor/bin/phpunit --no-coverage --testsuite Unit && composer psalm`. CI runs both deptrac gates in `.github/workflows/architecture.yml` (`make deptrac` + `make notification-deptrac`).

## Refactor / review workflow

Triggered via the `php-refactor-workflow` skill — it owns the audit-first procedure, review checklist, and dependency-ordered execution pattern.

The HW7 modular refactor is driven by **BMad-METHOD** (planning + per-story create-story → dev-story → code-review). Its planning specs (PRD, architecture, epics, project context) live under `specs/` and are committed as the durable record of *what* was planned and *why*; the verbose per-story dev/review logs and sprint status stay under `_bmad-output/` (gitignored — process notes, not specs). Keep the LikeC4 model in sync via `likec4-architecture-sync` when changing components/wiring.
