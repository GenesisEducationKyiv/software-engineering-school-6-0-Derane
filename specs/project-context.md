---
project_name: 'github-release-notifier'
user_name: 'valerii'
date: '2026-06-03'
sections_completed:
  ['technology_stack', 'language_rules', 'domain_dto_rules', 'repository_rules', 'di_rules', 'boundary_rules', 'architecture_rules', 'testing_rules', 'quality_rules', 'workflow_rules']
status: 'complete'
existing_patterns_found: 9
rule_count: 25
optimized_for_llm: true
---

# Project Context for AI Agents

_This file contains critical rules and patterns that AI agents must follow when implementing code in this project. Focus on unobvious details that agents might otherwise miss._

---

## Technology Stack & Versions

**Language / runtime**
- PHP `^8.2` (Psalm pinned to analyze at `phpVersion=8.4`)

**HTTP / API**
- Slim 4 (`slim/slim ^4.12`, `slim/psr7 ^1.6`) with PHP-DI (`php-di/php-di ^7.0`, `php-di/slim-bridge ^3.4`)
- gRPC via Spiral RoadRunner (`spiral/roadrunner-grpc ^3.5`, `google/protobuf ^4.33`) — contract in `proto/release_notifier.proto`, generated code in `generated/Grpc` + `generated/GPBMetadata`

**Persistence / cache / mail / messaging**
- PostgreSQL via PDO; SQL migrations in `migrations/*.sql` (run with `composer migrate`)
- Redis via Predis (`predis/predis ^2.2`) — used **only** for GitHub-API response caching
- PHPMailer SMTP (`phpmailer/phpmailer ^6.9`)
- GitHub API client over Guzzle (`guzzlehttp/guzzle ^7.8`)
- RabbitMQ via `php-amqplib/php-amqplib` — cross-service integration messages to the extracted notification service (introduced over Epics C–D; pure-PHP, no `ext-amqp`)

**Config / logging**
- `vlucas/phpdotenv ^5.6` for env; Monolog (`monolog/monolog ^3.5`) behind PSR-3 (`psr/log ^3.0`)

**Quality / test tooling**
- PHPUnit `^10.5`, Psalm `^6.16` (errorLevel 1 = strictest), PHP_CodeSniffer `^3.9` (PSR-12)
- deptrac `^4.6` (`deptrac/deptrac`) — architecture gate wired into `composer lint`; rules in `deptrac.yaml`, day-one leaks in `deptrac.baseline.yaml`
- Behat acceptance (`twentytwo-labs/behat-open-api`, `behat/mink`) validating against `swagger.yaml`
- Mockery `^1.6`, FakerPHP `^1.24`; Playwright e2e under `tests/e2e`

**Autoload (PSR-4)**
- `App\` → `src/`, `Grpc\` → `generated/Grpc/`, `GPBMetadata\` → `generated/GPBMetadata/`, `Tests\` → `tests/`

## Critical Implementation Rules

### PHP / language
- `final readonly class` is the DEFAULT for stateless services, repositories,
  controllers, middleware. Skip only when mutable state is truly required
  (e.g. `SafeGitHubCacheDecorator`).
- Put `#[\Override]` on EVERY method that implements an interface.
- Code targets PHP `^8.2` but MUST pass Psalm at `errorLevel=1` analyzed as PHP 8.4
  → 100% type coverage, no `mixed` leaks.

### Domain / DTO / aggregates
- **Entities are aggregate roots** extending `App\Shared\Domain\Aggregate\AggregateRoot`,
  recording domain events (`recordThat` / `pullDomainEvents`).
- **Value-object snapshots stay anemic + readonly** (e.g. `Release` — no identity/lifecycle).
  Anemic DTOs have NO `from*()` / static factories; construct them through a
  `*FactoryInterface` impl that parses API/DB payloads.
- Validation lives in injected `*Validator` classes — never inline `filter_var`/regex
  inside services.

### Shared kernel & buses (`src/Shared`)
- Value objects `App\Shared\Domain\ValueObject\{RepositoryName,EmailAddress,ReleaseTag}`;
  `AggregateRoot`; `DomainEvent`.
- **In-house CQRS** (NO Symfony Messenger): `Domain\Bus\{Command,Query}` contracts +
  `Infrastructure\Bus\InMemory{Command,Query}Bus` adapters. Use-cases are
  `*CommandHandler`/`*QueryHandler`; thin drivers dispatch through the bus.
- **Two event planes:** in-process domain events via a synchronous in-memory **PSR-14**
  plane (`Infrastructure\Event\InMemoryEventDispatcher` + `ListenerProvider`; listener
  exceptions PROPAGATE → keeps the flow outbox-free); cross-service via **RabbitMQ**
  integration messages (`SendReleaseEmail` is an integration command).

### Repositories (per-consumer ISP)
- Use narrow interfaces: `*Reader`, `*Writer`, `*Registrar`, `*Source`, `*Finder`.
- Either split read/write into separate classes (`TrackedRepositoryReader`/`Writer`)
  OR one class implementing several narrow interfaces (`SubscriptionRepository`).
  Match whatever the area you touch already does.

### Dependency injection (`config/container.php`)
- Single DI definitions file. Bind INTERFACES only.
- To share one instance across two interfaces, alias the second to the first.
  NEVER register a concrete class as a DI key just to share an instance.

### Boundaries / wire-format (public contract — do not break silently)
- JSON response shape, gRPC reply, and Behat assertions are the public contract.
  Internal refactors must preserve them unless the user opts in.
- Exception→status mapping lives ONLY in `ExceptionStatusMap`, used by both
  `ErrorHandlerMiddleware` (HTTP) and `Grpc\ReleaseNotifierService` (gRPC).
- Rate-limit / control-flow exceptions are caught at the orchestration layer
  (`ScannerService::scan`), NOT inside the unit method.

### Architecture boundaries
- **Target layout:** bounded contexts under `src/<Context>/<Module>/{Domain,Application,Infrastructure}`
  (Subscription, RepositoryTracking, Releases, Scanning, Notification\Publishing,
  Notification\Sending) + `src/Shared` + deployables in `apps/` (`apps/monolith/{http,grpc,scanner}`,
  `apps/notification`). Dependency rule points inward (Domain ← Application ← Infrastructure).
- **deptrac enforces boundaries** (`deptrac.yaml`): Domain depends on nothing outward; no
  cross-context Infrastructure deps; `apps/notification` may not reach monolith-only contexts.
  Grant legitimate cross-context *port* edges explicitly; the baseline only SHRINKS.
- **Strangler migration in progress:** legacy flat dirs (`src/Domain`, `src/Service`,
  `src/Repository`, `src/Controller`, `src/Grpc`, `src/GitHub`, `src/Notifier`, `src/Cache`,
  `src/Validation`, `src/Factory`, `src/Config`, ...) are mapped to a transitional `Legacy.*`
  deptrac layer and moved into context homes over Epic B.
- gRPC contract: `proto/release_notifier.proto`; generated PHP in `generated/Grpc`
  + `generated/GPBMetadata` (namespaces `Grpc\`, `GPBMetadata\`). Never hand-edit
  generated code or put app logic there. App code is `App\` → `src/`.
- Redis/Predis caches GitHub-API responses ONLY. Postgres (PDO, `migrations/*.sql`)
  is the source of truth.

### Testing
- `tests/` mirrors `src/`. PHPUnit suites: "Unit" (excludes Acceptance/Integration/e2e)
  and "Integration".
- Mockery for mocks, FakerPHP for fixtures.
- Behat acceptance validates against `swagger.yaml` and NEEDS the docker stack —
  ASK before booting it. Playwright e2e under `tests/e2e`.

### Quality gates (all three MUST pass before claiming done)
- `composer lint` (PHPCS PSR-12 **+ deptrac** architecture rules)
- `./vendor/bin/phpunit --no-coverage --testsuite Unit` (env-independent unit gate; the full
  run also exercises the Integration suite, which needs the docker stack — Postgres/Redis)
- `composer psalm` (100% type coverage)

### Workflow / commands
- Migrations: `composer migrate`. Scanner: `composer scanner`. gRPC: `composer grpc`.
- Refactor/review tasks → `php-refactor-workflow` skill (audit-first).
- Keep the LikeC4 model in `docs/architecture/` in sync via `likec4-architecture-sync`
  when changing components/wiring.

---

## Usage Guidelines

**For AI Agents:**

- Read this file before implementing any code in this repo.
- Follow ALL rules exactly; when in doubt, prefer the more restrictive option.
- Treat `CLAUDE.md` as the authoritative source — this file is its distilled,
  agent-facing companion. If they ever diverge, `CLAUDE.md` wins.

**For Humans:**

- Keep this file lean and focused on what agents would otherwise miss.
- Update when the stack, conventions, or quality gates change.
- Remove rules that become obvious or obsolete over time.

Last Updated: 2026-06-06
