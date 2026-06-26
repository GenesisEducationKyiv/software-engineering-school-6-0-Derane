---
name: code-organization
description: Enforce code organization principles - "Directory X contains ONLY class type X", DDD/CQRS naming patterns, PHP best practices, type safety, SOLID principles, and hardcoded config extraction to .env. Use when reviewing code structure, placing classes, refactoring, fixing CI failures related to structure, or extracting hardcoded configuration values.
---

# Code Organization Skill

## Core Principle

> **Directory X contains ONLY class type X**

This is the fundamental rule for code organization in this codebase.

## Context (Input)

- Creating new classes and determining correct directory
- Moving classes to proper locations
- Reviewing code for organizational compliance
- Fixing organizational issues from code reviews
- Ensuring class names match their responsibilities
- **Refactoring code structure** (moving, renaming, splitting classes)
- **Fixing CI failures** that stem from structural/naming issues
- **Extracting hardcoded config values** (TTLs, timeouts, limits) to `.env`

## Task (Function)

Enforce strict code organization principles: proper directory structure, DDD/CQRS naming conventions, specific variable names, type safety, SOLID principles, and PHP best practices.

## Directory Type Classification

Classes MUST be in directories matching their type:

| Directory          | Contains ONLY                   | Example                                    |
| ------------------ | ------------------------------- | ------------------------------------------ |
| `Factory/`         | Object factories                | `ReleaseFactory`, `SubscriptionFactory`    |
| `Serialization/`   | Serializers/deserializers       | `SendReleaseEmailSerializer`               |
| `Cache/`           | Cache adapters/decorators       | `RedisGitHubCache`, `GitHubReleaseCache`   |
| `Listener/`        | PSR-14 domain-event listeners   | `WhenSubscriptionCreatedThenLog`           |
| `Http/`            | Slim 4 controllers/middleware   | `SubscriptionController`                    |
| `Persistence/`     | PDO repository implementations  | `PdoSubscriptionRepository`                |
| `Bus/`             | CQRS bus implementations        | `InMemoryCommandBus`, `InMemoryQueryBus`   |
| `Messaging/`       | RabbitMQ adapters               | `RabbitPublisher`, `RabbitConsumer`        |
| `Metrics/`         | Metrics emitters/formatters     | `MetricsService`, `PrometheusFormatter`    |
| `Health/`          | Health checks                   | `DatabaseHealthCheck`                       |
| `Clock/`           | Clock implementations           | `SystemClock`                              |
| `Error/`           | Exception-to-status mapping     | `ExceptionStatusMap`                       |
| `ValueObject/`     | Self-validating value objects   | `EmailAddress`, `RepositoryName`, `ReleaseTag` |

### Directory Creation Guardrails

- **NEVER create new directories autonomously** — every new class-type directory MUST follow a well-known software engineering pattern (Factory, Serializer, Decorator, Listener, Repository, etc.) AND be explicitly requested/approved by the user. When in doubt, use an existing directory.
- Do not invent ad-hoc class-type directories or suffixes. The following are **explicitly forbidden**:
  - `Applier/`, `Attacher/`, `Enricher/` — not well-known patterns
  - `Augmenter/` — not a well-known pattern
  - `Helper/`, `Util/`, `Manager/` — vague catch-all anti-patterns
  - `Service/` as a generic dumping ground — leads to anemic domain models; use specific pattern names instead (Factory, Source, Finder, Publisher, etc.). NOTE: this targets new ad-hoc `Service/` directories — the legacy flat `src/Service` dir is being drained over Epic B, not extended.
- Any proposed new directory MUST be a **well-known software engineering pattern** (e.g. Factory, Builder, Strategy, Observer, Adapter, Decorator, Proxy, Iterator, Mediator, etc.) — not an invented verb-noun.
- Use existing DDD/CQRS directory types and naming patterns from this skill.
- Follow DDD and CQRS strictly — all class organization must align with our Clean Architecture layers (Domain ← Application ← Infrastructure) and the in-house CQRS Command/Query bus patterns.

## DDD/CQRS Naming Patterns

### By Layer and Type

| Layer              | Class Type           | Naming Pattern                            | Example                                  |
| ------------------ | -------------------- | ----------------------------------------- | ---------------------------------------- |
| **Domain**         | Aggregate Root       | `{EntityName}.php`                         | `Subscription.php`                       |
|                    | Value Object         | `{ConceptName}.php`                        | `EmailAddress.php`, `RepositoryName.php`  |
|                    | Anemic VO snapshot   | `{ConceptName}.php`                        | `Release.php`, `ReleaseSnapshot.php`     |
|                    | Domain Event         | `{Entity}{PastTenseAction}.php`            | `SubscriptionCreated.php`, `NewReleaseDetected.php` |
|                    | Port (read)          | `{Concept}Reader.php` / `{Concept}Source.php` / `{Concept}Finder.php` | `ScanCandidateSource.php`, `SubscriberFinder.php` |
|                    | Port (write)         | `{Concept}Writer.php` / `{Concept}Registrar.php` | `ScanProgressWriter.php`, `TrackedRepositoryRegistrar.php` |
|                    | Exception            | `{SpecificError}Exception.php`            | `SubscriptionNotFoundException.php`, `RateLimitException.php` |
| **Application**    | Command              | `{Action}{Entity}Command.php`             | `SubscribeCommand.php`                   |
|                    | Command Handler      | `{Action}{Entity}CommandHandler.php` / `{Action}Handler.php` | `SubscribeCommandHandler.php`, `ScanReleasesHandler.php` |
|                    | Query                | `{Action}{Entity}Query.php`               | `FindSubscriptionByIdQuery.php`          |
|                    | Query Handler        | `{Action}{Entity}Handler.php`             | `FetchLatestReleaseHandler.php`          |
|                    | Response DTO         | `{Entity}{Type}Response.php`              | `FetchLatestReleaseResponse.php`, `SubscriptionResponse.php` |
|                    | Response Factory     | `{Entity}ResponseFactoryInterface.php`   | `SubscriptionResponseFactoryInterface.php` |
| **Infrastructure** | Repository           | `{Technology}{Entity}Repository.php`     | `PdoSubscriptionRepository.php`          |
|                    | Cache adapter        | `{Technology}{Concept}Cache.php`         | `RedisGitHubCache.php`                   |
|                    | Bus implementation   | `{Strategy}{Type}Bus.php`                | `InMemoryCommandBus.php`                 |
|                    | Messaging adapter    | `Rabbit{Role}.php`                        | `RabbitPublisher.php`                    |
|                    | Controller           | `{Entity}Controller.php`                  | `SubscriptionController.php`             |
|                    | Listener (PSR-14)    | `{Action}On{Event}Listener.php`            | `PublishReleaseEmailsOnNewReleaseDetectedListener.php` |
|                    | Serializer           | `{Message}Serializer.php`                 | `SendReleaseEmailSerializer.php`        |

### Directory Structure by Layer

```
src/{Context}/{Module}/
├── Application/
│   ├── {UseCase}/        ← one folder per use-case: Command/Query + Handler (+ Response)
│   ├── Find/             ← e.g. FindSubscriptionByIdQuery.php + FindSubscriptionByIdHandler.php
│   ├── Subscribe/        ← e.g. SubscribeCommand.php + SubscribeCommandHandler.php
│   └── *ResponseFactory  ← Application-level response factories + DTOs
├── Domain/
│   ├── {Entity}.php          ← Aggregate roots (extend Shared\Domain\Aggregate\AggregateRoot)
│   ├── {Concept}.php         ← Anemic readonly VO snapshots (e.g. Release)
│   ├── {Event}.php           ← Domain Events ({Entity}{PastTense})
│   ├── {Concept}Source.php   ← Read ports (Reader/Source/Finder)
│   ├── {Concept}Writer.php   ← Write ports (Writer/Registrar)
│   └── *Exception.php        ← Domain Exceptions
└── Infrastructure/
    ├── Persistence/      ← PDO repository implementations
    ├── Cache/            ← Predis/Redis cache adapters (GitHub-API cache only)
    ├── Http/             ← Slim 4 controllers / middleware
    ├── Listener/         ← PSR-14 in-process domain-event listeners
    ├── Factory/          ← Infrastructure factories
    └── Serialization/    ← Wire-format serializers (RabbitMQ messages)
```

Shared kernel lives under `src/Shared/{Domain,Application,Infrastructure}` (value objects, `AggregateRoot`, `DomainEvent`, in-house `Bus/{Command,Query}` contracts, PSR-14 `Infrastructure/Event` plane). The extracted notification service lives entirely under `apps/notification/` with its own `src/`, tests and deptrac ruleset.

## Verification Checklist

When creating or reviewing a class, verify:

1. ✅ **Class Type Matches Directory** (Directory X contains ONLY class type X)
   - Example: `RedisGitHubCache` in `Cache/`, NOT `Persistence/`
2. ✅ **Class Name Follows DDD/CQRS Pattern** for its type
3. ✅ **Namespace Matches Directory Structure** exactly (PSR-4 root is `App\` → `src/`)
4. ✅ **Class Name Reflects Actual Functionality**
5. ✅ **Correct Layer** (Domain/Application/Infrastructure)
6. ✅ **Domain Layer Has NO Framework / Infrastructure Imports** (no Slim, Predis, PDO, php-amqplib, RoadRunner, Guzzle)
7. ✅ **Variable Names Are Specific** (not vague)
   - ✅ `$releaseFactory`, `$githubCache` (specific)
   - ❌ `$factory`, `$cache` (too vague)
8. ✅ **Parameter Names Match Actual Types**
   - ✅ `mixed $value` when accepts any type
   - ❌ `string $binary` when accepts mixed
9. ✅ **No "Helper" or "Util" Classes** (extract specific responsibilities)
10. ✅ **No ad-hoc class-type suffixes/directories** (`Applier`, `Attacher`, `Enricher`, `Augmenter`, `Helper`, `Util`, `Manager`, generic `Service`)
11. ✅ **New directories are explicit and standard**, not agent-invented — must be explicitly approved by the user
12. ✅ **`#[\Override]` on every interface-implementation method**
13. ✅ **`final readonly class`** for stateless services, repositories, controllers, middleware, bus adapters (skip `readonly` only when mutable state is required — see `SafeGitHubCacheDecorator`, `AggregateRoot`)

## PHP Best Practices

### Required Patterns

- ✅ **Constructor property promotion**
- ✅ **Inject ALL dependencies** (no default instantiation)
- ✅ **Use `readonly`** when appropriate (default for stateless classes)
- ✅ **Use `final`** for classes that shouldn't be extended
- ✅ **`#[\Override]`** on every interface-implementation method
- ✅ **No static methods** except named constructors on self-validating value objects (`fromString()`) — anemic DTO snapshots are built through a `*FactoryInterface`, never `from*` static methods

### Anti-Patterns (Forbidden)

- ❌ **Helper/Util/Manager classes / generic Service classes** - Extract specific responsibilities; a generic `Service` leads to anemic domain models
- ❌ **Non-standard pattern directories** - No `Applier/`, `Attacher/`, `Enricher/`, `Augmenter/` — use well-known patterns (Factory, Serializer, Decorator, Listener, etc.)
- ❌ **Default instantiation in constructors** - Inject dependencies
- ❌ **Vague variable names** - Be specific
- ❌ **Namespace mismatches** - Must match directory structure
- ❌ **Ad-hoc directory/class type inventions** - Use established patterns only; NEVER create new directories without explicit user approval
- ❌ **Autonomous directory creation** - Agent must NEVER create a new class-type directory on its own; any new directory must follow a well-known software engineering pattern and be approved by the user
- ❌ **Constructor defaults that instantiate collaborators** - Inject the dependency instead of using `new` in `__construct(...)` defaults
- ❌ **`from*` static methods on anemic DTO snapshots** - Build them through a `*FactoryInterface` (e.g. `ReleaseFactory::fromGitHubPayload()`), not a static `Release::from*()`. (Self-validating VOs like `EmailAddress`/`RepositoryName` MAY use `fromString()` — that idiom is for VOs whose construction IS validation.)
- ❌ **Inline `filter_var` / regex validation inside services** - Input validation lives in the self-validating Shared VOs (`EmailAddress`, `RepositoryName`, `ReleaseTag`); constructing the VO IS the validation. Do not reintroduce standalone validator classes (the legacy `App\Validation\*` ones were absorbed into the VOs).
- ❌ **Bare `array`, `list`, or `iterable` collections of domain objects** - Use typed collection classes instead. Example: subscribers travel as a `SubscriberCollection` (an `IteratorAggregate<int, SubscriberRef>` + `Countable`), not a bare `SubscriberRef[]`. Internal storage inside the collection class may still use `array`.
- ❌ **Untyped `array` in method signatures** - Always specify the array's content type via docblock (`list<string>`, `array<string, int>`) or use a typed collection class.

## Factory Pattern (Maintainability & Flexibility)

> **Avoid hardcoded `new ClassName()` in production source code — use named constructors (VOs) or Factory classes (DTOs / complex objects)**

### Factory Methods on Self-Validating Value Objects

Self-validating value objects MAY provide static named constructors:

```php
// ❌ BAD: bypassing validation / scattering construction
$name = new RepositoryName($raw);   // acceptable inside the VO/handlers,
                                    // but prefer the named constructor at boundaries

// ✅ GOOD: named constructor (construction IS the validation)
$name = RepositoryName::fromString($raw);
```

Named constructors (`fromString()`) are the **preferred** way to instantiate self-validating VOs at boundaries. The constructor remains public for use within named constructors and tests.

Anemic readonly DTO snapshots (e.g. `Release`, `ReleaseSnapshot`) follow a **different** rule: NO `from*` static methods — build them through a dedicated `*FactoryInterface`.

### When Factory Classes Are REQUIRED (Production Code)

1. Objects with injected dependencies (clock, config, loggers)
2. Objects requiring complex construction logic
3. Objects needing different implementations per environment
4. Objects created from external input (GitHub API payloads, RabbitMQ messages, HTTP bodies)

### When Direct `new` Is ACCEPTABLE

- Inside factory methods and Factory classes (that's their purpose)
- In test code (simplicity over abstraction)
- For framework-required patterns (e.g., `throw new RepositoryNotFoundException()`)
- Inside the value object's own named constructors
- Inside aggregate-root factory/named constructors (e.g. `Subscription::subscribe(...)`)

### Factory Benefits

- ✅ Centralized object creation logic
- ✅ Easy to inject different implementations
- ✅ Configuration changes don't affect consumers
- ✅ Single place for validation/transformation
- ✅ Enables dependency injection for complex objects

### Example: Bad vs Good

```php
// ❌ BAD: inline construction from raw GitHub payload, scattered across callers
public function latestRelease(RepositoryName $repository): Release
{
    $payload = $this->apiClient->fetchLatest((string) $repository);

    return new Release(
        (string) $payload['tag_name'],
        (string) $payload['name'],
        (string) $payload['html_url'],
        (string) $payload['published_at'],
        (string) $payload['body'],
    );
}

// ✅ GOOD: Factory owns the mapping/normalization
public function latestRelease(RepositoryName $repository): Release
{
    $payload = $this->apiClient->fetchLatest((string) $repository);

    return $this->releaseFactory->fromGitHubPayload($payload);
}
```

### Factory Naming Convention

- `{ObjectName}Factory` implements `{ObjectName}FactoryInterface` - creates `{ObjectName}` instances
- Location: under the owning context's `Infrastructure/Factory/` (e.g. `App\Releases\Sourcing\Infrastructure\Factory\ReleaseFactory`)
- Example: `ReleaseFactory` creates `Release`; `SubscriptionFactory` creates `Subscription`

## Type Safety: Classes Over Arrays

> **Arrays are NOT allowed for collections of domain objects that already have a dedicated collection type. Use the collection class instead.**

Arrays lack type safety and self-documentation. Use concrete classes instead.

### Array vs Class Comparison

| Pattern       | Bad (Array)                                  | Good (Class)                                          |
| ------------- | -------------------------------------------- | ----------------------------------------------------- |
| Return data   | `return ['tag' => $t, 'url' => $u]`          | `return $this->releaseFactory->fromGitHubPayload(...)`|
| Method params | `function publish(array $subscribers)`       | `function publish(SubscriberCollection $subscribers)` |
| Events data   | `['type' => 'created', 'id' => $id]`         | `new SubscriptionCreated($id, ...)`                   |
| Registry      | `private array $subscribers`                 | `private SubscriberCollection $subscribers`           |

### Benefits of Typed Classes

- ✅ IDE autocompletion and refactoring support
- ✅ Static analysis (Psalm errorLevel 1) catches type errors
- ✅ Self-documenting code
- ✅ Encapsulation (validation in constructor)
- ✅ Single Responsibility
- ✅ Open/Closed principle (extend via new classes)

### Collection Pattern

```php
// ❌ BAD: bare array of value objects threaded through the publisher
$subscribers = [
    new SubscriberRef($id1, $email1),
    new SubscriberRef($id2, $email2),
];

// ✅ GOOD: typed collection (IteratorAggregate<int, SubscriberRef> + Countable)
$subscribers = new SubscriberCollection([
    new SubscriberRef($id1, $email1),
    new SubscriberRef($id2, $email2),
]);
```

### When Arrays ARE Acceptable

- Simple key-value maps for serialization output (`toArray()` / wire-format encoding)
- Framework / driver integration points requiring arrays (PDO rows, decoded JSON payloads at the boundary)
- Temporary internal data within a single method, or internal storage inside a collection class

## Cross-Cutting Concerns Pattern

> **Use PSR-14 domain-event listeners for cross-cutting concerns (metrics, logging, cross-service publishing), NOT direct injection into handlers**

Our two event planes:
- **In-process domain events → PSR-14**, synchronous, in-memory. Listener exceptions **propagate** (a publish failure aborts marker advancement — keeps the flow outbox-free).
- **Cross-service → RabbitMQ integration messages** (e.g. `SendReleaseEmail`), published by a thin listener after the monolith resolves recipients.

### Anti-Pattern: Logging/metrics inside a Command Handler

```php
// ❌ WRONG: cross-cutting concern wired into the command handler
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private LoggerInterface $logger,  // Wrong place!
    ) {}

    #[\Override]
    public function __invoke(Command $command): void
    {
        $subscription = Subscription::subscribe(/* ... */);
        $this->repository->create($subscription);
        $this->logger->info('subscription created');  // Violates SRP
    }
}
```

### Correct Pattern: Dedicated PSR-14 Listener

```php
// ✅ CORRECT: clean command handler dispatches the domain event
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private EventDispatcherInterface $eventDispatcher,
    ) {}

    #[\Override]
    public function __invoke(Command $command): void
    {
        $subscription = Subscription::subscribe(/* ... */);
        $this->repository->create($subscription);

        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);  // SubscriptionCreated
        }
    }
}

// ✅ CORRECT: cross-cutting concern in a dedicated PSR-14 listener
final readonly class WhenSubscriptionCreatedThenLog
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(SubscriptionCreated $event): void
    {
        // PSR-14 plane is synchronous and in-process — listener exceptions
        // propagate by design, so observability/cross-service publishing failures
        // abort the unit of work rather than silently dropping it.
        $this->logger->info('subscription created', ['id' => $event->subscriptionId]);
    }
}
```

## Common Issues and Fixes

### Issue 1: Class in Wrong Type Directory

```bash
❌ WRONG:
src/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCache.php

✅ CORRECT:
src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php

# Fix:
mv src/Releases/Sourcing/Infrastructure/Persistence/RedisGitHubCache.php \
   src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
# Update namespace and all imports
```

### Issue 2: Vague Variable Names

```php
❌ WRONG:
private ReleaseFactory $factory;  // Factory of what?

✅ CORRECT:
private ReleaseFactoryInterface $releaseFactory;  // Specific!
```

### Issue 3: Misleading Parameter Names

```php
❌ WRONG:
public function fromGitHubPayload(array $tagName): Release  // Accepts the whole payload, not a tag

✅ CORRECT:
public function fromGitHubPayload(array $payload): Release  // Accurate!
```

### Issue 4: Helper/Util Classes

```php
❌ WRONG:
class GitHubHelper {
    public function validateRepositoryName() {}
    public function buildReleaseFromPayload() {}
    public function cacheKey() {}
}

✅ CORRECT: Extract specific responsibilities
- RepositoryName VO (Shared/Domain/ValueObject/) — construction IS validation
- ReleaseFactory (Releases/Sourcing/Infrastructure/Factory/) — builds Release from payload
- the cache-key logic belongs inside the relevant *Cache adapter (Cache/)
```

### Issue 5: Namespace Mismatch

```php
❌ WRONG:
// File: src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
namespace App\Releases\Sourcing\Infrastructure\Persistence;  // Mismatch!

✅ CORRECT:
// File: src/Releases/Sourcing/Infrastructure/Cache/RedisGitHubCache.php
namespace App\Releases\Sourcing\Infrastructure\Cache;  // Matches directory!
```

## Decision Tree: Where Does It Belong?

```text
What does the class DO?

├─ Creates complex objects / maps external payloads? → Infrastructure/Factory/
├─ Serializes a message to/from the wire? → Infrastructure/Serialization/
├─ Reads/writes Redis (GitHub-API cache)? → Infrastructure/Cache/
├─ Reads/writes Postgres via PDO? → Infrastructure/Persistence/
├─ Handles an HTTP request (Slim) / middleware? → Infrastructure/Http/
├─ Reacts to a domain event (PSR-14)? → Infrastructure/Listener/
├─ Publishes/consumes RabbitMQ messages? → Shared/Infrastructure/Messaging/Rabbit/
├─ Self-validates a primitive concept? → Shared/Domain/ValueObject/
├─ A use-case (Command/Query + Handler)? → Application/{UseCase}/
├─ An aggregate / domain event / port / domain exception? → Domain/
└─ Something else? → Ask the user before creating a new directory!
```

## Verification Commands

```bash
# Check namespace consistency + architecture boundaries
make lint        # PHPCS PSR-12 + deptrac
make psalm       # 100% type coverage (errorLevel 1)

# Find organizational issues
grep -r "class.*Helper" src/      # Find Helper classes
grep -r "class.*Util" src/        # Find Util classes
grep -r "private.*\$factory;" src/  # Find vague names

# Verify architecture compliance
make deptrac  # Must show 0 violations against deptrac.yaml/deptrac.baseline.yaml
```

## Dependency Injection: Bind Interfaces, Alias to Share

> **Bind interfaces only in `config/container.php`. To share ONE instance across two interfaces, alias the second to the first — never use a concrete class as a DI key just to share an instance.**

### Rule

DI definitions key on **interfaces** (ports), not concrete classes. When one concrete repository implements several narrow per-consumer interfaces (ISP), define the canonical port once and alias the others to it so they all resolve to the same instance.

### Example

```php
// config/container.php

// ✅ Canonical binding: the interface → its concrete implementation
SubscriptionRepository::class => static fn($c) => new PdoSubscriptionRepository(
    $c->get(PDO::class),
    $c->get(SubscriptionFactoryInterface::class),
),

// ✅ Alias the other narrow ports to the same instance (ISP: one class, several ports)
SubscriberFinder::class      => static fn($c) => $c->get(SubscriptionRepository::class),
SubscriptionCountPort::class => static fn($c) => $c->get(SubscriptionRepository::class),

// ❌ WRONG: keying on a concrete class just to share an instance
// PdoSubscriptionRepository::class => static fn($c) => new PdoSubscriptionRepository(...),
// SubscriberFinder::class => static fn($c) => $c->get(PdoSubscriptionRepository::class),
```

### When an Explicit Binding/Alias is REQUIRED

- An interface (port) has **more than one** narrow consumer (the ISP `*Reader`/`*Writer`/`*Finder`/`*Source` split)
- A concrete needs **constructor arguments** the container cannot infer (PDO handle, config values like cache TTL, named connections)

### When It Is REDUNDANT

- If you already bound `SubscriptionRepository::class`, do NOT also bind `PdoSubscriptionRepository::class` — share via the interface alias instead.

## Constraints (Never Do This)

**NEVER**:

- Place class in wrong type directory (violates "Directory X contains ONLY class type X")
- Allow Domain layer to import framework/infrastructure code (Slim, Predis, PDO, php-amqplib, RoadRunner, Guzzle)
- Allow cross-context Infrastructure dependencies (only explicitly granted Domain *port* edges in `deptrac.yaml`)
- Use vague variable names (`$factory`, `$cache` — be specific!)
- Create "Helper" or "Util" classes (extract specific responsibilities)
- Allow namespace to mismatch directory structure
- Use arrays for collections of domain objects when a typed collection exists (e.g. `SubscriberCollection`)
- Use untyped `array` in method signatures — always specify content type via docblock or use collection classes
- Add `from*` static methods to anemic DTO snapshots — build them via a `*FactoryInterface`
- Inline `filter_var`/regex validation inside services — validate in the self-validating Shared VOs
- Reintroduce standalone validator classes (absorbed into the VOs)
- Use constructor defaults that instantiate collaborators — inject the dependency instead
- Inject cross-cutting concerns (metrics, logging) into command/query handlers — use PSR-14 listeners
- Create complex objects directly without factories in production code
- Omit `#[\Override]` on an interface-implementation method
- Key a DI definition on a concrete class just to share an instance — alias to the interface

**ALWAYS**:

- Verify "Directory X contains ONLY class type X" principle
- Use specific variable names (`$releaseFactory`, not `$factory`)
- Use accurate parameter names (match actual types)
- Ensure namespace matches directory structure exactly (`App\` → `src/`)
- Extract specific responsibilities from Helper/Util classes
- Prefer typed classes/collections over arrays for structured data
- Always specify array content types in method signatures (e.g. `list<string>`, `array<string, int>`)
- Validate via the self-validating Shared VOs
- Inject dependencies instead of instantiating constructor defaults
- Use named constructors (`fromString()`) for self-validating VOs; `*FactoryInterface` for anemic DTOs
- Use PSR-14 listeners for cross-cutting concerns
- Use factories for complex object creation in production code
- Put `#[\Override]` on every interface-implementation method
- Bind interfaces only in DI; alias to share one instance across two interfaces

## Related Skills

- **ci-workflow**: Use code-organization principles when fixing CI failures that stem from structural issues
- **code-review**: References this skill for organization verification during PR reviews
- **implementing-ddd-architecture**: DDD/CQRS patterns and Clean Architecture layer structure
- **deptrac-fixer**: Fixes architectural boundary violations (layer moves vs. file placement)
- **testing-workflow**: Move and update test files when relocating classes
- **quality-standards**: Maintains overall code quality metrics and protected gates

## Hardcoded Configuration Values → `.env` Extraction

> **Configurable values (TTLs, timeouts, limits, sizes, batch counts) belong in `.env`, not as class constants.**

### When to Extract

Extract a constant to `.env` when it represents:

- **Time durations**: cache TTLs, timeouts, scan intervals, expiration periods
- **Rate limits**: max requests, windows, thresholds
- **Sizes**: scan batch sizes, page limits, body sizes
- **Retry / confirm configuration**: publish-confirm timeouts, delays, max attempts
- **Infrastructure tunables**: Redis cache TTLs, queue settings

### When NOT to Extract

Keep as constants when the value is:

- **Protocol/spec-defined**: HTTP status codes, gRPC status codes
- **Domain invariants**: validation rules that are part of the domain model (e.g. allowed `RepositoryName` shape)

### Extraction Pattern (3-Step)

This pattern is already in use: `REDIS_CACHE_TTL` flows from `.env` → `config/settings.php` → `config/container.php` → the cache adapters' `$ttl` constructor param.

**Step 1**: Add the env variable to `.env` and `.env.example`

```dotenv
# .env / .env.example
REDIS_CACHE_TTL=600
RABBITMQ_CONFIRM_TIMEOUT_SECONDS=5
```

**Step 2**: Read it in `config/settings.php` (with a sensible default + cast)

```php
// config/settings.php
'redis' => [
    'host'      => $_ENV['REDIS_HOST'] ?? 'localhost',
    'port'      => (int) ($_ENV['REDIS_PORT'] ?? 6379),
    'cache_ttl' => (int) ($_ENV['REDIS_CACHE_TTL'] ?? 600),
],
```

**Step 3**: Bind it in `config/container.php` and inject via a constructor parameter

```php
// config/container.php
GitHubReleaseCache::class => static fn($c) => new GitHubReleaseCache(
    $c->get(GitHubCacheInterface::class),
    $c->get(ReleaseFactoryInterface::class),
    $settings['redis']['cache_ttl'],   // ← injected, not a const
),
```

```php
// ❌ BEFORE: Hardcoded constant
final readonly class GitHubReleaseCache implements LatestReleaseCacheInterface
{
    private const TTL = 600;
}

// ✅ AFTER: Injected from .env via settings + container
final readonly class GitHubReleaseCache implements LatestReleaseCacheInterface
{
    public function __construct(
        private GitHubCacheInterface $cache,
        private ReleaseFactoryInterface $releaseFactory,
        private int $ttl,
    ) {
    }
}
```

### Common Extraction Candidates

| Pattern in Source                                | Extract To `.env`                       |
| ------------------------------------------------ | --------------------------------------- |
| `private const TTL = <seconds>`                  | `REDIS_CACHE_TTL=<seconds>`             |
| `private const DEFAULT_CONFIRM_TIMEOUT_SECONDS`  | `RABBITMQ_CONFIRM_TIMEOUT_SECONDS=<n>`  |
| `private const SCAN_BATCH_SIZE = <n>`            | `GITHUB_SCAN_BATCH_SIZE=<n>`            |
| `private const SCAN_INTERVAL = <seconds>`        | `GITHUB_SCAN_INTERVAL=<seconds>`        |
| Constructor default `= 600`                      | Remove default, bind via container      |

### Verification After Extraction

```bash
make lint                # PHPCS PSR-12 + deptrac
make psalm               # Verify type safety
make test                # Unit suite (update mocks for new constructor params)
make integration         # Verify runtime binding works (needs the Docker stack)
make ci                  # Full validation → "✅ CI checks successfully passed!"
```

## CI Integration: When CI Fails

When `make ci` fails, consult this skill if the failure involves:

| CI Failure Indicator                 | Code Organization Fix                               |
| ------------------------------------ | --------------------------------------------------- |
| Class not found / namespace mismatch | Verify namespace matches directory structure        |
| Deptrac violation after moving class | Check layer/context placement (Domain/Application/Infra) |
| Psalm type errors after refactoring  | Check that imports and namespaces were all updated  |
| Test failures after class move       | Move test file too, update test namespace + imports |
| Missing `#[\Override]`               | Add the attribute to every interface-impl method    |

### Refactoring Checklist (Before Running CI)

When moving, renaming, or restructuring classes:

- [ ] Class in correct directory for its type (see Decision Tree above)
- [ ] Namespace matches directory structure exactly (`App\` → `src/`)
- [ ] All `use` imports updated in `src/` and `tests/`
- [ ] Test file moved to mirror source structure
- [ ] Test namespace updated
- [ ] `config/container.php` references updated (if service was explicitly bound/aliased)
- [ ] Raw SQL migration in `migrations/*.sql` updated (if persistence shape moved/changed)
- [ ] `#[\Override]` present on every interface-implementation method
- [ ] Hardcoded config values extracted to `.env` / `config/settings.php` if applicable
- [ ] `make lint && make psalm && make deptrac && make test` pass

## Related Documentation

See `reference/troubleshooting.md` for detailed troubleshooting and `examples/organization-fixes.md` for real-world examples.
