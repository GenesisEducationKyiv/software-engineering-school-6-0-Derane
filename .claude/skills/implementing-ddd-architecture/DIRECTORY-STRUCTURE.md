# DDD Directory Structure Reference

**Learn where to place files in this project's modular Clean Architecture by following its bounded-context layout (CodelyTV-style pragmatic DDD).**

> The root namespace is `App\` mapped to `src/` (PSR-4, see `composer.json`). The extracted
> notification service under `apps/notification/` mirrors the same layering with its own
> `composer.json`, `src/`, `tests/`, and a service-local `deptrac.yaml`.

## Quick Reference: File Placement Decision Tree

```
Is it business logic?
├─ YES → Domain layer
│   ├─ Has identity / lifecycle? → Aggregate (Domain/, extends Shared\Domain\Aggregate\AggregateRoot)
│   ├─ No identity, immutable, self-validating? → Value Object (reuse Shared VO, or new Domain VO)
│   ├─ No identity, immutable, anemic snapshot? → readonly DTO (Domain/, built via a *FactoryInterface)
│   ├─ Something happened in-process? → Domain Event (Domain/, implements Shared\Domain\DomainEvent)
│   ├─ Data-access / collaboration contract? → Port interface (Domain/: *Reader/*Writer/*Finder/...)
│   └─ Business error? → Domain Exception (Domain/)
│
├─ Is it orchestration / a use case?
│   ├─ YES → Application layer
│   │   ├─ Write operation? → Command + Handler (Application/<UseCase>/)
│   │   ├─ Read operation? → Query + Response + Handler (Application/<UseCase>/)
│   │   └─ Pure orchestration with no Domain of its own? → Scanning context (Application only)
│   │
└─ Is it a technical / external concern?
    └─ YES → Infrastructure layer
        ├─ Database access? → PDO repository (Infrastructure/Persistence/)
        ├─ GitHub-API cache? → Predis adapter (Infrastructure/Cache/, GitHub responses only)
        ├─ Cross-service message? → RabbitMQ publisher/consumer (Infrastructure/, via php-amqplib)
        ├─ HTTP driver? → Slim controller (Infrastructure/Http/)
        ├─ gRPC driver? → RoadRunner handler (legacy src/Grpc/ during the transition)
        ├─ Domain-event reaction? → Listener (Infrastructure/Listener/, wired via PSR-14)
        └─ Snapshot/entity assembly? → Factory (Infrastructure/Factory/)
```

## Complete Directory Structure (this project)

```
src/
├── Subscription/                       # Bounded Context
│   └── Subscriptions/                  # Module
│       ├── Application/                # Use cases (one folder per use-case)
│       │   ├── Subscribe/
│       │   │   ├── SubscribeCommand.php
│       │   │   └── SubscribeCommandHandler.php
│       │   ├── Unsubscribe/
│       │   ├── Find/                   # Queries: FindSubscriptionBy...Query + Handler
│       │   ├── List/                   # ListSubscriptionsQuery + Handler
│       │   ├── SubscriptionResponse.php / SubscriptionPageResponse.php
│       │   └── SubscriptionResponseFactory.php (+Interface)
│       │
│       ├── Domain/                     # Pure business logic
│       │   ├── Subscription.php                # Aggregate Root (extends AggregateRoot)
│       │   ├── SubscriptionPage.php            # anemic readonly snapshot
│       │   ├── SubscriberRef.php / SubscriberCollection.php
│       │   ├── SubscriptionCreated.php         # Domain Event
│       │   ├── SubscriptionNotFoundException.php
│       │   ├── SubscriptionRepository.php      # Port (write+read)
│       │   ├── SubscriberFinder.php            # Port (narrow read, for Publishing)
│       │   └── SubscriptionCountPort.php       # Port (narrow read, for metrics)
│       │
│       └── Infrastructure/             # Technical adapters
│           ├── Persistence/PdoSubscriptionRepository.php   # implements all three ports
│           ├── Factory/SubscriptionFactory.php (+Interface), SubscriberRefFactory.php (+Interface)
│           ├── Http/SubscriptionController.php             # Slim driver → CommandBus/QueryBus
│           └── Listener/WhenSubscriptionCreatedThenLog.php
│
├── RepositoryTracking/Repositories/    # Context / Module (scan registry + markers)
│   ├── Domain/                         # RepositoryStatus (aggregate), ports, events
│   │   ├── RepositoryStatus.php
│   │   ├── ReleaseSeenAdvanced.php / RepositoryChecked.php   # Domain Events
│   │   ├── TrackedRepositoryRegistrar.php / ScanCandidateSource.php / ScanProgressWriter.php
│   │   └── RepositoryStatusReader.php / RepositoryCountPort.php
│   └── Infrastructure/Persistence/Pdo...Reader.php, Pdo...Writer.php + Factory/
│
├── Releases/Sourcing/                  # Context / Module (GitHub release sourcing)
│   ├── Application/FetchLatestRelease/ + RepositoryExists/   # Queries + Handlers + Responses
│   ├── Domain/                         # Release (anemic snapshot), DetectedRelease, NewReleaseDetected
│   │   ├── Release.php                 # anemic readonly DTO (no identity/lifecycle)
│   │   ├── NewReleaseDetected.php      # Domain Event (owned here; carries Release types)
│   │   ├── ReleaseSource.php           # Port
│   │   └── RateLimitException.php
│   └── Infrastructure/                 # GitHubApiClient, GitHubApiReleaseSource, Cache/ (Predis), Factory/
│
├── Scanning/Scanner/                   # Orchestration context — NO Domain layer
│   ├── Application/ScanReleases/ScanReleasesCommand.php + Handler, ReleaseDetector.php
│   └── Infrastructure/Cli/ScannerCliRunner.php   # CLI driver → CommandBus
│
├── Notification/Publishing/            # Monolith publisher side
│   ├── Application/PublishReleaseEmailsForRelease.php
│   ├── Domain/SendReleaseEmail.php (integration message), ReleaseSnapshot.php, ports
│   └── Infrastructure/RabbitReleaseNotificationPublisher.php, Listener/, Serialization/, Factory/
│
├── Controller/ Grpc/ Migration/        # Legacy flat dirs (transitional; drain over Epic B)
│
└── Shared/                             # Shared kernel (cross-context)
    ├── Domain/
    │   ├── Aggregate/AggregateRoot.php             # recordThat / pullDomainEvents
    │   ├── DomainEvent.php                         # in-process event contract
    │   ├── Clock.php
    │   ├── Bus/
    │   │   ├── Command/{Command,CommandBus,CommandHandler,CommandNotRegistered}.php
    │   │   └── Query/{Query,QueryBus,QueryHandler,QueryNotRegistered,Response}.php
    │   ├── ValueObject/{EmailAddress,RepositoryName,ReleaseTag,Pagination}.php
    │   └── Exception/{InvalidArgumentException,RepositoryNotFoundException,ValidationException}.php
    │
    ├── Application/Pagination/PaginationFactory.php (+Interface)
    │
    └── Infrastructure/
        ├── Bus/{InMemoryCommandBus,InMemoryQueryBus}.php   # in-house bus adapters
        ├── Event/{InMemoryEventDispatcher,ListenerProvider}.php   # PSR-14 plane
        ├── Error/ExceptionStatusMap.php                    # one exception→status mapping
        ├── Clock/SystemClock.php
        ├── Http/{ApiKeyMiddleware,ErrorHandlerMiddleware}.php
        ├── Messaging/Rabbit/{RabbitConnection,RabbitPublisher,RabbitConsumer}.php
        ├── Metrics/ and Health/
        └── Persistence/

apps/
├── monolith/{http,grpc,scanner}/       # placeholder stubs (real entrypoints: public/index.php, bin/grpc.php, bin/scanner.php)
└── notification/                       # the extracted Notification\Sending service (own composer.json, src, tests, deptrac.yaml)
```

## File Naming Conventions

### Domain Layer

| Type                 | Naming Pattern                       | Example                              |
| -------------------- | ------------------------------------ | ------------------------------------ |
| Aggregate / Entity   | `{EntityName}.php`                   | `Subscription.php`, `RepositoryStatus.php` |
| Self-validating VO   | `{ConceptName}.php` (in Shared)      | `EmailAddress.php`, `RepositoryName.php`, `ReleaseTag.php` |
| Anemic readonly DTO  | `{ConceptName}.php`                  | `Release.php`, `ReleaseSnapshot.php` |
| Domain Event         | `{Entity}{PastTenseAction}.php`      | `SubscriptionCreated.php`, `ReleaseSeenAdvanced.php` |
| Port interface       | `{Concept}{Reader/Writer/Finder/...}.php` | `SubscriberFinder.php`, `ScanProgressWriter.php`, `ReleaseSource.php` |
| Domain Exception     | `{SpecificError}Exception.php`       | `RateLimitException.php`             |

### Application Layer

| Type             | Naming Pattern                | Example                          |
| ---------------- | ----------------------------- | -------------------------------- |
| Command          | `{Action}Command.php`         | `SubscribeCommand.php`           |
| Command Handler  | `{Action}CommandHandler.php`  | `SubscribeCommandHandler.php`    |
| Query            | `{Action}Query.php`           | `FetchLatestReleaseQuery.php`    |
| Query Handler    | `{Action}Handler.php`         | `FetchLatestReleaseHandler.php`  |
| Response         | `{Action}Response.php`        | `FetchLatestReleaseResponse.php` |

### Infrastructure Layer

| Type               | Naming Pattern                       | Example                              |
| ------------------ | ------------------------------------ | ------------------------------------ |
| PDO Repository     | `Pdo{Entity}{Repository/Reader/Writer}.php` | `PdoSubscriptionRepository.php`, `PdoTrackedRepositoryReader.php` |
| Cache adapter      | `Redis{Concept}Cache.php` (Predis)   | `RedisGitHubCache.php`               |
| Slim controller    | `{Entity}Controller.php`             | `SubscriptionController.php`         |
| RabbitMQ adapter   | `Rabbit{Concept}.php`                | `RabbitReleaseNotificationPublisher.php` |
| Listener           | `When{Event}Then{Action}.php`        | `WhenNewReleaseDetectedThenPublishReleaseEmails.php` |
| Factory            | `{Concept}Factory.php` (+`Interface`)| `ReleaseFactory.php` / `ReleaseFactoryInterface.php` |

## Creating New Files: Step-by-Step

### Creating a New Use Case in an Existing Context

```bash
# 1. Create the use-case folder under the module's Application layer
mkdir -p src/RepositoryTracking/Repositories/Application/MarkChecked

# 2. Result:
src/RepositoryTracking/Repositories/Application/MarkChecked/
├── MarkCheckedCommand.php          # implements App\Shared\Domain\Bus\Command\Command
└── MarkCheckedCommandHandler.php   # implements CommandHandler<MarkCheckedCommand>, #[\Override] __invoke
```

### Adding a New Aggregate

1. **Aggregate** (Domain): `src/<Context>/<Module>/Domain/<Entity>.php` — extends `AggregateRoot`
2. **Value Objects** (reuse Shared, or new Domain VO): self-validating in the constructor
3. **Port interface(s)** (Domain): per-consumer ISP (`<Entity>Repository`, `<Entity>Finder`, ...)
4. **Domain Events** (Domain): `src/<Context>/<Module>/Domain/<Event>.php` (implements `DomainEvent`)
5. **Exceptions** (Domain): `src/<Context>/<Module>/Domain/<Error>Exception.php`
6. **SQL migration** (raw SQL): `migrations/NNN_create_<table>.sql`
7. **PDO adapter** (Infrastructure): `src/<Context>/<Module>/Infrastructure/Persistence/Pdo<Entity>Repository.php`
8. **Command/Query** (Application): under a use-case folder
9. **Handler** (Application): same folder, dispatched via the in-house bus
10. **Wire DI** (`config/container.php`): bind interfaces only; alias to share an instance

### Adding a New Feature to an Existing Context

Adding a "mark checked" feature to RepositoryTracking:

```
src/RepositoryTracking/Repositories/
├── Application/
│   └── MarkChecked/
│       ├── MarkCheckedCommand.php          # NEW
│       └── MarkCheckedCommandHandler.php   # NEW
└── Domain/
    ├── RepositoryStatus.php                # ADD method: markChecked()
    └── RepositoryChecked.php               # NEW Domain Event
```

## Anti-Pattern: Wrong File Placement

### WRONG: Business logic in Infrastructure

```
src/Subscription/Subscriptions/Infrastructure/SubscriptionValidator.php
// Input validation belongs in the self-validating Shared VOs (EmailAddress, RepositoryName).
```

**Fix**: construct `App\Shared\Domain\ValueObject\EmailAddress` / `RepositoryName` — the VO validates itself.

### WRONG: Framework / infrastructure code in Domain

```
src/Subscription/Subscriptions/Domain/Subscription.php
use PDO;   // ❌ persistence concern in Domain
```

**Fix**: keep the aggregate pure; do all SQL in `Infrastructure/Persistence/PdoSubscriptionRepository.php`.

### WRONG: Use-case logic in an Entity

```
src/Subscription/Subscriptions/Domain/Subscription.php
public function sendWelcomeEmail()   // ❌ Application/Infrastructure concern!
```

**Fix**: record `SubscriptionCreated`; react in a PSR-14 listener under `Infrastructure/Listener/`.

### WRONG: `from*` static constructor on an anemic DTO

```
src/Releases/Sourcing/Domain/Release.php
public static function fromGitHubPayload(array $p): self   // ❌ banned on anemic DTOs
```

**Fix**: build it through `ReleaseFactoryInterface` in `Infrastructure/Factory/`. (`fromString()` is only for self-validating VOs.)

## Quick Checks

Before committing new files:

```bash
# Verify architecture (zero new violations beyond the only-shrinking baseline)
make deptrac

# Check no framework / infrastructure imports leaked into any Domain layer
grep -rn "use PDO\|use Predis\|use PhpAmqpLib\|use Slim\|use Spiral\|use PHPMailer" src/*/*/Domain/ src/Shared/Domain/

# Ensure handlers carry the @implements generic and the #[\Override] attribute
grep -rln "implements CommandHandler\|implements QueryHandler" src/*/*/Application/
```

## Related Skills

- **[deptrac-fixer](../deptrac-fixer/SKILL.md)** — Fix violations when files are in the wrong layer
- **[code-organization](../code-organization/SKILL.md)** — "directory X contains only class type X", naming, placement
- **[quality-standards](../quality-standards/SKILL.md)** — Maintain the protected quality gates

---

**Remember**: Structure reflects intent. Proper file placement makes the architecture self-documenting.
