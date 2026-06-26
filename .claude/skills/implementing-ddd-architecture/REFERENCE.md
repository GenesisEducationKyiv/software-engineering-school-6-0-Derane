# DDD Architecture Reference Guide

**Detailed patterns, workflows, and implementation guidelines for this project's modular Clean Architecture (CodelyTV-style pragmatic DDD).**

> The root namespace is `App\` mapped to `src/` (PSR-4). Persistence is PDO + PostgreSQL
> with raw SQL migrations in `migrations/*.sql`. There is no ORM. The extracted notification
> service under `apps/notification/` mirrors this layering with its own composer/src/tests.

This document provides comprehensive explanations and step-by-step workflows referenced from [SKILL.md](SKILL.md).

## Table of Contents

- [Layer Responsibilities](#layer-responsibilities)
- [Creating a New Aggregate](#creating-a-new-aggregate)
- [Fixing Deptrac Violations](#fixing-deptrac-violations)
- [Complete Pattern Examples](#complete-pattern-examples)
- [Persistence Configuration](#persistence-configuration)
- [Event-Driven Architecture Details](#event-driven-architecture-details)
- [Repository / Port Pattern Details](#repository--port-pattern-details)
- [Factory Pattern for Object Creation](#factory-pattern-for-object-creation)
- [Choosing the Right Value Type](#choosing-the-right-value-type)
- [Anti-Patterns Deep Dive](#anti-patterns-deep-dive)

---

## Layer Responsibilities

### Domain Layer: Pure Business Logic

**Purpose**: Encapsulate core business logic and business rules.

**Allowed Dependencies**: NONE outward (pure PHP + `App\Shared\Domain\*` only)

**Contains**:

- **Aggregates / Entities**: Objects with identity & lifecycle (e.g. `Subscription`, `RepositoryStatus`) — extend `AggregateRoot`
- **Value Objects**: Immutable, self-validating, identity-less (e.g. `EmailAddress`, `RepositoryName`, `ReleaseTag`)
- **Anemic readonly DTO snapshots**: identity-less, lifecycle-less data carriers (e.g. `Release`, `ReleaseSnapshot`) — built via a `*FactoryInterface`
- **Domain Events**: in-process events implementing `Shared\Domain\DomainEvent` (e.g. `SubscriptionCreated`, `NewReleaseDetected`)
- **Port interfaces**: contracts for persistence and collaboration (per-consumer ISP)
- **Domain Exceptions**: business-rule violations (e.g. `RateLimitException`)

**Strict Rules**:

- ❌ NO Slim / PSR-7 HTTP types
- ❌ NO PDO / SQL / Predis / Redis
- ❌ NO PHPMailer, php-amqplib (RabbitMQ), Spiral RoadRunner gRPC
- ❌ NO persistence or HTTP concerns
- ✅ Pure business logic ONLY

**Example Aggregate**:

```php
// src/Subscription/Subscriptions/Domain/Subscription.php
namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

// Not readonly: PHP forbids readonly children of the non-readonly AggregateRoot.
final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,
        private readonly RepositoryName $repository,
        private readonly string $createdAt
    ) {
    }

    // Named constructor expresses intent and records the creation event.
    public static function subscribe(EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        $subscription = new self(null, $email, $repository, $createdAt);
        $subscription->recordThat(new SubscriptionCreated(
            (string) $email,
            (string) $repository,
            new \DateTimeImmutable()
        ));

        return $subscription;
    }

    public static function reconstitute(int $id, EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        return new self($id, $email, $repository, $createdAt);
    }

    public function id(): ?int { return $this->id; }
    public function email(): string { return (string) $this->email; }
    public function repository(): string { return (string) $this->repository; }
}
```

### Application Layer: Use Case Orchestration

**Purpose**: Orchestrate use cases and coordinate between Domain and Infrastructure.

**Allowed Dependencies**: same-context Domain, `Shared\Domain`, `Shared\Application`, and granted cross-context Domain *ports*.

**Contains**:

- **Command Handlers**: write operations (implement `CommandHandler<T>`)
- **Query Handlers**: read operations (implement `QueryHandler<T, R>`, return a `Response`)
- **Commands / Queries / Responses**: immutable intent/request/result DTOs
- **Response factories**: build response DTOs from domain results

**Rules**:

- ❌ NO business logic (delegate to Domain)
- ✅ Orchestrate workflows; build VOs from primitives
- ✅ Dispatch domain events that the aggregate recorded
- ✅ Use constructor dependency injection (ports, not adapters)

**Example Command Handler**:

```php
// src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommandHandler.php
namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Clock;
use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/** @implements CommandHandler<SubscribeCommand> */
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private ReleaseSource $gitHubService,                  // cross-context Domain port (granted)
        private TrackedRepositoryRegistrar $trackedRepositories, // cross-context Domain port (granted)
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        // Constructing the self-validating VOs IS the input validation.
        $email = new EmailAddress($command->email);
        $repository = new RepositoryName($command->repository);

        if (!$this->gitHubService->repositoryExists($repository)) {
            throw new RepositoryNotFoundException($command->repository);
        }

        $this->trackedRepositories->ensureExists($command->repository);

        $subscription = Subscription::subscribe(
            $email,
            $repository,
            $this->clock->now()->format(\DateTimeInterface::ATOM)
        );

        $this->repository->create($subscription);

        // Dispatch the in-process domain events the aggregate recorded (PSR-14).
        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
```

### Infrastructure Layer: Technical Implementation

**Purpose**: Implement technical details and external integrations.

**Allowed Dependencies**: same-context Domain + Application, `Shared.*`, and the framework (Slim, PDO, Predis, PHPMailer, php-amqplib, gRPC).

**Contains**:

- **PDO repositories**: concrete persistence with raw SQL
- **Predis cache adapters**: GitHub-API responses only
- **RabbitMQ publishers/consumers**: cross-service integration messages
- **Slim controllers / gRPC handlers / CLI runners**: thin drivers → the in-house bus
- **PSR-14 listeners**: react to in-process domain events
- **Factories**: assemble entities/snapshots from external data

**Rules**:

- ✅ Implement the Domain ports
- ✅ Handle persistence / transport details
- ❌ NO business logic; ❌ NO cross-context Infrastructure dependencies

**Example Repository Implementation**:

```php
// src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionRepository.php
namespace App\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use PDO;

final readonly class PdoSubscriptionRepository implements SubscriptionRepository
{
    public function __construct(
        private PDO $pdo,
        private SubscriptionFactoryInterface $subscriptionFactory
    ) {
    }

    #[\Override]
    public function create(Subscription $subscription): Subscription
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository) VALUES (:email, :repository)
             ON CONFLICT (email, repository) DO NOTHING
             RETURNING id, email, repository, created_at'
        );
        $stmt->execute(['email' => $subscription->email(), 'repository' => $subscription->repository()]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        // ... (fall back to a SELECT on conflict, then reconstitute via the factory) ...
        return $this->subscriptionFactory->reconstitute($row !== false ? $row : []);
    }

    #[\Override]
    public function findById(int $id): ?Subscription
    {
        $stmt = $this->pdo->prepare('SELECT id, email, repository, created_at FROM subscriptions WHERE id = :id');
        $stmt->execute(['id' => $id]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->subscriptionFactory->reconstitute($row) : null;
    }
}
```

---

## Creating a New Aggregate

**Complete workflow for adding a new aggregate to the system.**

### Step 1: Identify the Bounded Context

**Questions to answer**:

- Does this belong to an existing context (`Subscription`, `RepositoryTracking`, `Releases`, `Scanning`, `Notification\Publishing`)?
- Or to the extracted `Notification\Sending` service under `apps/notification/`?
- Or does it warrant a new context?

**Example**: tracking the scan marker for a repository → belongs in `RepositoryTracking\Repositories`.

### Step 2: Design the Domain Model (Domain Layer)

**Location**: `src/<Context>/<Module>/Domain/<Entity>.php`

**Tasks**:

1. Create the aggregate class extending `AggregateRoot`
2. Reuse Shared VOs (or add a new self-validating VO) for invariant-bearing fields
3. Define business methods (not setters!) that record domain events
4. Use a `private` constructor + named constructors (`subscribe()`, `existing()`, `reconstitute()`)

**Example**:

```php
// src/RepositoryTracking/Repositories/Domain/RepositoryStatus.php
namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;

final class RepositoryStatus extends AggregateRoot
{
    private function __construct(
        private readonly string $fullName,
        private ?string $lastSeenTag,
        private ?string $lastCheckedAt,
    ) {
    }

    public static function existing(string $fullName): self
    {
        return new self($fullName, null, null);
    }

    // Business method — not a setter. Mutates state AND records an event.
    public function markReleaseSeen(string $tag): void
    {
        $this->lastSeenTag = $tag;
        $this->recordThat(new ReleaseSeenAdvanced($this->fullName, $tag, new \DateTimeImmutable()));
    }

    public function fullName(): string { return $this->fullName; }
    public function lastSeenTag(): ?string { return $this->lastSeenTag; }
}
```

### Step 3: Define Port Interface(s) (Domain Layer)

**Location**: `src/<Context>/<Module>/Domain/`

Apply per-consumer ISP — split read/write and name by consumer need.

```php
// src/RepositoryTracking/Repositories/Domain/ScanProgressWriter.php
namespace App\RepositoryTracking\Repositories\Domain;

interface ScanProgressWriter
{
    public function save(RepositoryStatus $status): void;
}
```

```php
// src/RepositoryTracking/Repositories/Domain/RepositoryStatusReader.php
namespace App\RepositoryTracking\Repositories\Domain;

interface RepositoryStatusReader
{
    public function findByFullName(string $fullName): ?RepositoryStatus;
}
```

### Step 4: Create the SQL Migration (raw SQL)

**Location**: `migrations/NNN_<description>.sql`

There is no ORM — schema lives in plain SQL applied by `bin/migrate.php` (`make migrate`).

```sql
-- migrations/00X_create_repository_status.sql
CREATE TABLE IF NOT EXISTS repository_status (
    full_name       TEXT PRIMARY KEY,
    last_seen_tag   TEXT,
    last_checked_at TIMESTAMPTZ
);
```

### Step 5: Implement the Adapter (Infrastructure Layer)

**Location**: `src/<Context>/<Module>/Infrastructure/Persistence/Pdo<Entity><Role>.php`

One adapter class may implement several narrow ports.

```php
// src/RepositoryTracking/Repositories/Infrastructure/Persistence/PdoTrackedRepositoryWriter.php
namespace App\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\RepositoryStatus;
use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use PDO;

final readonly class PdoTrackedRepositoryWriter implements ScanProgressWriter
{
    public function __construct(private PDO $pdo) {}

    #[\Override]
    public function save(RepositoryStatus $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repository_status
             SET last_seen_tag = :tag, last_checked_at = NOW()
             WHERE full_name = :name'
        );
        $stmt->execute(['tag' => $status->lastSeenTag(), 'name' => $status->fullName()]);
    }
}
```

### Step 6: Create the Command / Query (Application Layer)

**Location**: `src/<Context>/<Module>/Application/<UseCase>/`

```php
// src/Scanning/Scanner/Application/ScanReleases/ScanReleasesCommand.php
namespace App\Scanning\Scanner\Application\ScanReleases;

use App\Shared\Domain\Bus\Command\Command;

final readonly class ScanReleasesCommand implements Command
{
}
```

### Step 7: Create the Handler (Application Layer)

```php
/** @implements CommandHandler<ScanReleasesCommand> */
final readonly class ScanReleasesHandler implements CommandHandler
{
    #[\Override]
    public function __invoke(Command $command): void
    {
        // Orchestrate: pull candidates, detect new releases, record markers, dispatch events.
    }
}
```

### Step 8: Define Domain Events (Domain Layer)

```php
// src/RepositoryTracking/Repositories/Domain/ReleaseSeenAdvanced.php
namespace App\RepositoryTracking\Repositories\Domain;

use App\Shared\Domain\DomainEvent;

final readonly class ReleaseSeenAdvanced implements DomainEvent
{
    public function __construct(
        public string $repository,
        public string $tag,
        private \DateTimeImmutable $occurredOn,
    ) {
    }

    #[\Override]
    public function occurredOn(): \DateTimeImmutable { return $this->occurredOn; }

    #[\Override]
    public function eventName(): string { return 'repository.release_seen_advanced'; }
}
```

### Step 9: Create Listeners (Infrastructure Layer, PSR-14)

```php
// src/Notification/Publishing/Infrastructure/Listener/PublishReleaseEmailsOnNewReleaseDetectedListener.php
namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Releases\Sourcing\Domain\NewReleaseDetected;

final readonly class PublishReleaseEmailsOnNewReleaseDetectedListener
{
    public function __construct(private PublishReleaseEmailsForRelease $publishReleaseEmails) {}

    public function __invoke(NewReleaseDetected $event): void
    {
        // Map the event into this context's snapshot, then delegate. Exceptions propagate.
    }
}
```

### Step 10: Verify Architecture

```bash
make deptrac     # zero new violations beyond the only-shrinking baseline
make test        # Unit suite (env-independent)
make integration # Integration suite (needs the docker stack: Postgres/Redis)
```

If violations exist, fix the code (never relax `deptrac.yaml` / `deptrac.baseline.yaml`).

---

## Fixing Deptrac Violations

**Complete guide to diagnosing and fixing architectural violations.**

### Violation Process

1. **Run Deptrac**: `make deptrac`
2. **Read Output Carefully**: understand what's wrong
3. **Identify Problem**: which layer, which dependency?
4. **Plan Refactor**: how to fix the architecture?
5. **Implement Fix**: move/refactor code
6. **Verify**: re-run `make deptrac`

### Common Violation Patterns

#### Violation Type 1: Domain → inline validation / framework

**Symptom**:

```
Domain must not depend on transport/framework concerns
src/Subscription/Subscriptions/Domain/Subscription.php
  inline filter_var(...) input validation in a Domain class
```

**Problem Code**:

```php
namespace App\Subscription\Subscriptions\Domain;

final class Subscription
{
    public function __construct(string $email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { // ❌ ad-hoc validation
            throw new \InvalidArgumentException('bad email');
        }
    }
}
```

**Solution**: use the self-validating Shared VO — constructing it IS the validation.

```php
namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\ValueObject\EmailAddress;

final class Subscription
{
    public function __construct(private readonly EmailAddress $email) {} // VO validated itself
}
```

```php
// EmailAddress rejects bad input with Shared\Domain\Exception\InvalidArgumentException,
// which ExceptionStatusMap maps to 400 / INVALID_ARGUMENT.
final readonly class EmailAddress implements \Stringable
{
    public function __construct(private string $value)
    {
        if (filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
    }
}
```

#### Violation Type 2: Domain → PDO / persistence

**Symptom**:

```
Domain must not depend on PDO
src/Releases/Sourcing/Domain/Release.php
  uses PDO
```

**Problem Code**:

```php
namespace App\Releases\Sourcing\Domain;

use PDO; // ❌ persistence in Domain

final class Release
{
    public static function load(PDO $pdo, int $id): self { /* ... */ }
}
```

**Solution**: keep the snapshot pure; do all SQL in an Infrastructure adapter, assembling via a factory.

```php
// Pure anemic readonly snapshot (no identity/lifecycle, no SQL)
namespace App\Releases\Sourcing\Domain;

final readonly class Release
{
    public function __construct(
        public ?string $tagName,
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body
    ) {
    }
}
```

```php
// Assembly lives in Infrastructure\Factory, behind a Domain-less *FactoryInterface.
namespace App\Releases\Sourcing\Infrastructure\Factory;

final readonly class ReleaseFactory implements ReleaseFactoryInterface
{
    #[\Override]
    public function fromGitHubPayload(array $payload): Release { /* build the Release */ }
}
```

#### Violation Type 3: Cross-context Infrastructure dependency

**Symptom**:

```
NotificationPublishing.Infrastructure must not depend on Subscription.Infrastructure
```

**Problem**: an Infrastructure class reaching directly into another context's Infrastructure (e.g. newing its PDO repository).

**Solution**: depend on the other context's **Domain port** instead, and grant the edge in `deptrac.yaml` if it is legitimate (cross-context **port** edges only — never Infrastructure→Infrastructure).

```php
// Publishing's use-case resolves recipients via the Subscription Domain port,
// not via Subscription's PDO adapter.
final readonly class PublishReleaseEmailsForRelease
{
    public function __construct(private SubscriberFinder $subscribers) {} // Subscription.Domain port
}
```

#### Violation Type 4: Infrastructure → Application handler called directly

**Symptom**:

```
Infrastructure must not depend on a concrete Application handler
src/.../Infrastructure/Listener/SomeListener.php
```

**Problem Code**:

```php
namespace App\Subscription\Subscriptions\Infrastructure\Listener;

use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler; // ❌ concrete handler

final readonly class SomeListener
{
    public function __construct(private SubscribeCommandHandler $handler) {}
}
```

**Solution**: depend on the in-house `CommandBus`, or react via a PSR-14 listener to a domain event.

```php
namespace App\Subscription\Subscriptions\Infrastructure\Listener;

use App\Shared\Domain\Bus\Command\CommandBus; // ✅ bus, not the concrete handler

final readonly class SomeListener
{
    public function __construct(private CommandBus $commandBus) {}
}
```

**Better Solution**: record a domain event in the aggregate and react in a PSR-14 listener (see Event-Driven Architecture).

---

## Complete Pattern Examples

See the [examples/ directory](examples/) for complete, working code examples:

- **01-entity-example.php**: aggregate root with business methods + domain events (`RepositoryStatus`)
- **02-value-object-examples.php**: choosing the right value type (self-validating VO vs anemic DTO vs behavior-bearing VO)
- **03-cqrs-pattern-example.php**: full CQRS flow (Command/Query → Handler → Domain/Port) on the in-house bus
- **04-fixing-deptrac-violations.php**: before/after code for the common violation types

---

## Persistence Configuration

### Raw SQL Migrations

**Location**: `migrations/NNN_<description>.sql`

There is no ORM and no mapping files. Schema is plain SQL applied in order by
`bin/migrate.php`:

```sql
-- migrations/00X_create_subscriptions.sql
CREATE TABLE IF NOT EXISTS subscriptions (
    id         BIGGENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    email      TEXT NOT NULL,
    repository TEXT NOT NULL,
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (email, repository)
);
```

```bash
make migrate   # apply pending migrations against the configured PostgreSQL
```

### Reconstitution via a Factory

Rows fetched by the PDO adapter are turned back into aggregates / snapshots through a
`*FactoryInterface` (kept out of Domain so the Domain stays pure):

```php
// src/Subscription/Subscriptions/Infrastructure/Factory/SubscriptionFactoryInterface.php
interface SubscriptionFactoryInterface
{
    /** @param array<string, mixed> $row */
    public function reconstitute(array $row): Subscription;
}
```

### GitHub-API Cache (Predis)

Redis (via Predis) caches **GitHub-API responses only** — never domain data. The cache
adapter lives in `Releases/Sourcing/Infrastructure/Cache/` behind a Domain-less interface,
with a `SafeGitHubCacheDecorator` (one of the few non-`readonly` classes, as it holds a
degradation flag) that fails open if Redis is unavailable.

---

## Event-Driven Architecture Details

### Two Event Planes

| Plane | Transport | Scope | Failure semantics |
| ----- | --------- | ----- | ----------------- |
| **In-process domain events** | PSR-14 (`InMemoryEventDispatcher`) | within one process | **synchronous**; listener exceptions **propagate** (a publish failure aborts marker advancement — outbox-free) |
| **Cross-service integration messages** | RabbitMQ (php-amqplib) | across services / queue | versioned schema + idempotency metadata (e.g. `SendReleaseEmail`) |

### Domain Event Structure

Domain events implement `App\Shared\Domain\DomainEvent` (in-process only):

```php
namespace App\Shared\Domain;

interface DomainEvent
{
    public function occurredOn(): \DateTimeImmutable;
    public function eventName(): string;
}
```

### Recording & Dispatch Flow

1. Aggregate records an event: `$this->recordThat($event)` (buffered in `AggregateRoot`)
2. Handler persists via the port: `$repository->create($entity)`
3. Handler pulls events: `$entity->pullDomainEvents()`
4. Handler dispatches each through the PSR-14 `EventDispatcherInterface`
5. `ListenerProvider` routes to the matching `When<Event>Then<Action>` listeners
6. Listeners run **synchronously**; a thrown exception propagates back to the handler

### Cross-Service Integration Messages

A cross-service message is a plain `final readonly class` (NOT a `DomainEvent`) carrying a
schema version and correlation id, serialized and published to RabbitMQ:

```php
// src/Notification/Publishing/Domain/SendReleaseEmail.php
final readonly class SendReleaseEmail
{
    public const string SCHEMA = 'SendReleaseEmail/v1';

    public function __construct(
        public string $schema,
        public string $eventId,         // correlation/trace id only
        public \DateTimeImmutable $occurredAt,
        public int $subscriptionId,
        public EmailAddress $email,
        public RepositoryName $repository,
        public ReleaseSnapshot $release
    ) {
    }
}
```

A PSR-14 listener (`PublishReleaseEmailsOnNewReleaseDetectedListener`) consumes the
in-process `NewReleaseDetected`, the Publishing use-case resolves recipients via its own
`SubscriberProvider` port — an Anti-Corruption Layer adapter
(`SubscriptionSubscriberProvider`) bridges to the Subscription context's `SubscriberFinder`
and translates each `SubscriberRef` into this context's own `Recipient`, so the Application
layer carries no cross-context dependency — and the `RabbitReleaseNotificationPublisher`
sends one `SendReleaseEmail` per recipient onto the queue. The extracted notification service
consumes them; consumer-side dedup is by the business key (subscriptionId + tag + repository).

---

## Repository / Port Pattern Details

### Port Method Naming & Per-Consumer ISP

Split read from write and name interfaces by the consumer's need. One adapter class may
implement several narrow ports (see `PdoSubscriptionRepository implements
SubscriptionRepository, SubscriberFinder, SubscriptionCountPort`).

- `create(Entity $e): Entity` / `save(Entity $e): void` — persist
- `findById(int $id): ?Entity` — find by id, nullable
- `findByEmailAndRepository(string $email, string $repository): ?Entity` — find by business key
- `ensureExists(string $fullName): void` — idempotent registration (`*Registrar`)
- `findSubscribersByRepository(RepositoryName $r): SubscriberCollection` — read for another context (`*Finder`)
- `countAll(): int` — narrow read for metrics (`*CountPort`)

### Typed Collections

When a module exposes a repeated group of domain objects, return a typed collection
(e.g. `SubscriberCollection`) rather than a bare `array`/`list`:

```php
// src/Subscription/Subscriptions/Domain/SubscriberFinder.php
interface SubscriberFinder
{
    public function findSubscribersByRepository(RepositoryName $repository): SubscriberCollection;
}
```

---

## Factory Pattern for Object Creation

### Why Use Factories?

**Key Principle**: build **anemic readonly DTO snapshots** (and reconstitute aggregates
from rows) through a `*FactoryInterface`, not through `from*` static methods on the DTO and
not via direct `new` in handlers/adapters.

**Benefits**:

- ✅ **Single Responsibility**: the factory owns the mapping from external/raw data
- ✅ **Testability**: easy to mock the factory in unit tests
- ✅ **Convention**: keeps the `from*`-on-DTO ban intact (`fromString()` stays VO-only)
- ✅ **DI**: factories are injected behind interfaces

**When to Use Factories**:

- Building anemic readonly DTO snapshots (`Release`, `ReleaseSnapshot`)
- Reconstituting aggregates from DB rows
- Multi-step assembly from external payloads (GitHub JSON)

**When NOT to Use Factories**:

- ✅ In **tests**: `new Release(...)` directly is fine
- ✅ Constructing a self-validating VO: `new EmailAddress($value)` (or its `fromString()`)
- ✅ Inside the factory itself (the factory encapsulates `new`)
- ✅ A named constructor on the aggregate (`Subscription::subscribe(...)`) — that is the intended construction path

### Factory Interface

**Location**: `src/<Context>/<Module>/Infrastructure/Factory/<Concept>FactoryInterface.php`

```php
namespace App\Releases\Sourcing\Infrastructure\Factory;

use App\Releases\Sourcing\Domain\Release;

interface ReleaseFactoryInterface
{
    /** @param array<string, mixed> $payload */
    public function fromGitHubPayload(array $payload): Release;
}
```

### Factory Implementation

```php
namespace App\Releases\Sourcing\Infrastructure\Factory;

use App\Releases\Sourcing\Domain\Release;

final readonly class ReleaseFactory implements ReleaseFactoryInterface
{
    #[\Override]
    public function fromGitHubPayload(array $payload): Release
    {
        // The factory is the ONLY place we 'new Release(...)' from a raw payload.
        return new Release(
            isset($payload['tag_name']) ? (string) $payload['tag_name'] : null,
            isset($payload['name']) ? (string) $payload['name'] : '',
            isset($payload['html_url']) ? (string) $payload['html_url'] : '',
            isset($payload['published_at']) ? (string) $payload['published_at'] : '',
            isset($payload['body']) ? (string) $payload['body'] : '',
        );
    }
}
```

### Using Factories in Adapters

**❌ BAD — `from*` static method on the anemic DTO**:

```php
final readonly class Release
{
    public static function fromGitHubPayload(array $p): self { /* ❌ banned on anemic DTOs */ }
}
```

**✅ GOOD — inject the factory interface**:

```php
final readonly class GitHubApiReleaseSource implements ReleaseSource
{
    public function __construct(
        private GitHubApiClientInterface $client,
        private ReleaseFactoryInterface $releaseFactory   // ✅ inject the factory
    ) {}

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        $payload = $this->client->latestRelease($repository);
        return $payload === null ? null : $this->releaseFactory->fromGitHubPayload($payload);
    }
}
```

### DI Registration

Factories and adapters are wired in `config/container.php`. **Bind interfaces only**, and
alias a second interface to the first to share one instance:

```php
// config/container.php (illustrative)
ReleaseFactoryInterface::class => DI\create(ReleaseFactory::class),

SubscriptionRepository::class => /* build PdoSubscriptionRepository(...) */,
// Same instance also serves the narrow read ports — alias, never re-key by concrete class.
SubscriberFinder::class     => DI\get(SubscriptionRepository::class),
SubscriptionCountPort::class => DI\get(SubscriptionRepository::class),
```

### Testing with Factories

**Production code**: use the injected factory.

**Test code**: `new` directly is fine.

```php
final class ReleaseFactoryTest extends TestCase
{
    public function testBuildsFromPayload(): void
    {
        // In tests, constructing directly is acceptable for simplicity.
        $expected = new Release('v1.2.0', 'Release v1.2.0', 'https://...', '2026-01-01T00:00:00Z', 'notes');

        $actual = (new ReleaseFactory())->fromGitHubPayload([
            'tag_name' => 'v1.2.0', 'name' => 'Release v1.2.0',
            'html_url' => 'https://...', 'published_at' => '2026-01-01T00:00:00Z', 'body' => 'notes',
        ]);

        self::assertEquals($expected, $actual);
    }
}
```

---

## Choosing the Right Value Type

### Decision Criteria

This codebase distinguishes **three** kinds of value:

1. **Self-validating Value Object** — immutable, identity-less, validates itself in the
   constructor. This is where input validation lives.
2. **Anemic readonly DTO snapshot** — immutable, identity-less, *no* validation/behavior;
   a pure data carrier built via a `*FactoryInterface`.
3. **Aggregate / Entity** — has identity & lifecycle; extends `AggregateRoot`; records events.

#### ✅ Use a Self-Validating Value Object When:

1. **The value must be valid by construction**
   - `EmailAddress` — `filter_var` in the constructor
   - `RepositoryName` — `owner/repo` regex in the constructor
   - `ReleaseTag` — non-empty/non-whitespace in the constructor
2. **It has identity-preserving behavior**
   - `equals()`, `owner()`/`repo()`, `__toString()`
3. **It's shared across contexts**
   - The Shared VOs are reused by Subscription, Releases, RepositoryTracking, Notification

A self-validating VO MAY expose a `fromString()` named constructor — that idiom is for VOs.

#### ✅ Use an Anemic readonly DTO Snapshot When:

1. **It only carries data across a boundary** — no identity, no lifecycle, no behavior
   - `Release` (a GitHub release snapshot)
   - `ReleaseSnapshot` (Publishing's decoupled copy that crosses the queue)
2. **Build it via a factory** — never add `from*` static constructors to it

```php
final readonly class Release
{
    public function __construct(
        public ?string $tagName,
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body
    ) {
    }
}
```

#### ✅ Use an Aggregate When:

- It has identity (an id / business key) and a lifecycle (state transitions)
- It enforces invariants and records domain events (`Subscription`, `RepositoryStatus`)

#### ❌ DON'T:

- ❌ Inline `filter_var`/regex in a service or handler instead of constructing the VO
- ❌ Add a `from*` static constructor to an anemic DTO (factories build those)
- ❌ Add behavior or validation to an anemic snapshot — promote it to a real VO/entity instead
- ❌ Reintroduce a standalone `App\Validation\*` validator class (absorbed into the VOs)

### Decision Tree

```
Does it have identity + lifecycle (state transitions, events)?
├─ YES → Aggregate / Entity (extends AggregateRoot)
└─ NO → Must it be valid by construction (input validation)?
    ├─ YES → Self-validating Value Object (validate in the constructor; may have fromString())
    └─ NO → Anemic readonly DTO snapshot (no behavior; build via a *FactoryInterface)
```

### Real Codebase Examples

#### ✅ Self-validating VO: `RepositoryName`

```php
// Justified: must be a valid 'owner/repo' by construction; reused across contexts.
final readonly class RepositoryName implements \Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(private string $value)
    {
        if (!(bool) preg_match(self::PATTERN, $this->value)) {
            throw new InvalidArgumentException('Invalid repository format. Expected: owner/repo');
        }
    }

    public function owner(): string { /* ... */ }
    public function repo(): string { /* ... */ }
}
```

#### ✅ Anemic DTO snapshot: `Release`

```php
// Justified: pure data carrier from the GitHub API; no identity, no behavior.
// Built via ReleaseFactoryInterface, NOT via a from* static method.
final readonly class Release { public function __construct(public ?string $tagName, /* ... */) {} }
```

#### ✅ Aggregate: `Subscription`

```php
// Justified: has identity (id + email/repository business key), a lifecycle, and records events.
final class Subscription extends AggregateRoot { /* subscribe(), reconstitute(), ... */ }
```

### Validation Strategy

**In this codebase, input validation happens by constructing the self-validating Shared VOs.**

1. **Driver (controller / gRPC handler / CLI)** — checks transport shape only (missing
   fields, non-JSON body) and throws `ValidationException`; then builds a Command/Query.
2. **Application handler** — constructs the Shared VOs from the command's primitives;
   a bad value throws `Shared\Domain\Exception\InvalidArgumentException`.
3. **`ExceptionStatusMap`** — the single source of truth maps both `ValidationException`
   and `InvalidArgumentException` to 400 / `INVALID_ARGUMENT` (HTTP and gRPC).
4. **Domain methods** — enforce business invariants only (state transitions), not input format.

```php
// One source of truth for exception → transport status (HTTP + gRPC).
final readonly class ExceptionStatusMap
{
    public function toHttpStatus(\Throwable $e): int
    {
        return match (true) {
            $e instanceof ValidationException,
            $e instanceof InvalidArgumentException => StatusCodeInterface::STATUS_BAD_REQUEST,
            $e instanceof RepositoryNotFoundException,
            $e instanceof SubscriptionNotFoundException => StatusCodeInterface::STATUS_NOT_FOUND,
            $e instanceof RateLimitException => StatusCodeInterface::STATUS_TOO_MANY_REQUESTS,
            default => StatusCodeInterface::STATUS_INTERNAL_SERVER_ERROR,
        };
    }
}
```

### Key Differences from "Pure" DDD

**This codebase does NOT:**

- ❌ Use a framework validator, annotations/attributes, or YAML validation configs
- ❌ Validate input format inside aggregates or anemic snapshots
- ❌ Add `from*` static constructors to anemic DTOs

**This codebase DOES:**

- ✅ Put input validation in self-validating Shared VOs (constructing the VO IS validating)
- ✅ Keep anemic snapshots free of behavior; build them via factories
- ✅ Enforce only business invariants in aggregate methods
- ✅ Map every domain exception to a status in `ExceptionStatusMap`

### Summary: Be Pragmatic!

✅ **DO**:

- Reach for a self-validating VO when a value must be valid by construction
- Use an anemic readonly DTO (via a factory) for pure data carriers
- Promote to an aggregate when there is identity + lifecycle
- Keep it simple (YAGNI)

❌ **DON'T**:

- Inline validation in services; reintroduce standalone validators
- Add behavior to anemic snapshots, or `from*` constructors to DTOs
- Wrap framework concerns into Domain

**Remember**: the goal is **maintainable, understandable code** that respects this project's conventions.

---

## Anti-Patterns Deep Dive

### 1. Business Logic in Command Handlers

**Why it's wrong**: handlers orchestrate; business rules live in the Domain.

**Example (WRONG)**:

```php
final readonly class MarkReleaseSeenHandler implements CommandHandler
{
    public function __invoke(Command $command): void
    {
        $status = $this->reader->findByFullName($command->fullName);

        // ❌ Business rule in the handler
        if ($status->lastSeenTag() === $command->tag) {
            throw new \RuntimeException('already seen');
        }
        // ... mutate via setters ...
    }
}
```

**Correct Approach**:

```php
// Handler orchestrates
final readonly class MarkReleaseSeenHandler implements CommandHandler
{
    #[\Override]
    public function __invoke(Command $command): void
    {
        $status = $this->reader->findByFullName($command->fullName);
        $status->markReleaseSeen($command->tag); // delegate to the aggregate
        $this->writer->save($status);

        foreach ($status->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}

// Aggregate owns the business logic + event
final class RepositoryStatus extends AggregateRoot
{
    public function markReleaseSeen(string $tag): void
    {
        $this->lastSeenTag = $tag;
        $this->recordThat(new ReleaseSeenAdvanced($this->fullName, $tag, new \DateTimeImmutable()));
    }
}
```

### 2. Anemic Domain Models

**Why it's wrong**: the Domain becomes a data bag; logic scatters across handlers.

**Example (WRONG)**:

```php
final class RepositoryStatus
{
    public function getLastSeenTag(): ?string { return $this->lastSeenTag; }
    public function setLastSeenTag(?string $t): void { $this->lastSeenTag = $t; }
}

// Logic + event creation in the handler — WRONG
final readonly class MarkReleaseSeenHandler implements CommandHandler
{
    public function __invoke(Command $command): void
    {
        $status = $this->reader->findByFullName($command->fullName);
        $status->setLastSeenTag($command->tag);
        $this->eventDispatcher->dispatch(new ReleaseSeenAdvanced(/* ... */)); // ❌ event minted in handler
    }
}
```

**Correct Approach**: move the mutation + event into `RepositoryStatus::markReleaseSeen()` (above).

### 3. Skipping the Self-Validating VO

**Why it's wrong**: validation gets duplicated/inconsistent (primitive obsession).

**Example (WRONG)**:

```php
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __invoke(Command $command): void
    {
        // ❌ ad-hoc validation, bypassing EmailAddress/RepositoryName
        if (!str_contains($command->email, '@')) {
            throw new \RuntimeException('bad email');
        }
    }
}
```

**Correct Approach**:

```php
final readonly class SubscribeCommandHandler implements CommandHandler
{
    #[\Override]
    public function __invoke(Command $command): void
    {
        // ✅ Constructing the self-validating VO IS the validation.
        $email = new EmailAddress($command->email);
        $repository = new RepositoryName($command->repository);
        // ...
    }
}
```

---

## Summary

This reference guide details how to implement the architecture correctly. Always remember:

1. **Domain is sacred** — no framework/infrastructure dependencies
2. **Handlers orchestrate** — business logic lives in the Domain
3. **Self-validating VOs validate input** — constructing the VO IS validation
4. **Respect deptrac** — fix the code, never the config (the baseline only shrinks)
5. **Rich models** — behavior + events, not just data

For working code examples, see the [examples/ directory](examples/).
