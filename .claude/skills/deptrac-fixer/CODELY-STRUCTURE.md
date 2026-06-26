# Bounded-Context Directory Structure Reference

**Where to move files when fixing Deptrac violations.**

This reference shows the correct directory structure for this project: a CodelyTV-style **Clean Architecture + pragmatic DDD** layout where each bounded context owns `Domain`, `Application`, and `Infrastructure` layers, with the dependency rule pointing **inward** (`Domain ← Application ← Infrastructure`). It is inspired by [CodelyTV's php-ddd-example](https://github.com/CodelyTV/php-ddd-example) but uses **our** real contexts, namespaces, and stack (Slim 4, RoadRunner gRPC, PDO/PostgreSQL, Predis, php-amqplib).

The autoload root is PSR-4 `App\` → `src/` (see `composer.json`). All examples below use real `App\…` FQCNs.

## Our Project Structure (`src/`)

```bash
src/
├── Shared/                                       # Shared kernel (cross-context, no outward deps)
│   ├── Domain/
│   │   ├── Aggregate/
│   │   │   └── AggregateRoot.php                  # recordThat() / pullDomainEvents()
│   │   ├── Bus/
│   │   │   ├── Command/
│   │   │   │   ├── Command.php                    # marker interface
│   │   │   │   ├── CommandBus.php                 # in-house bus (NOT Symfony Messenger)
│   │   │   │   ├── CommandHandler.php
│   │   │   │   └── CommandNotRegistered.php
│   │   │   └── Query/
│   │   │       ├── Query.php
│   │   │       ├── QueryBus.php
│   │   │       ├── QueryHandler.php
│   │   │       ├── QueryNotRegistered.php
│   │   │       └── Response.php
│   │   ├── ValueObject/                           # self-validating VOs
│   │   │   ├── EmailAddress.php                   # filter_var lives HERE, not in services
│   │   │   ├── RepositoryName.php                 # owner/repo regex lives HERE
│   │   │   └── ReleaseTag.php
│   │   ├── Exception/
│   │   │   └── InvalidArgumentException.php       # maps to 400/INVALID_ARGUMENT
│   │   ├── Clock.php
│   │   └── DomainEvent.php                        # in-process event contract (PSR-14 plane)
│   ├── Application/                               # cross-cutting application services
│   └── Infrastructure/                            # adapters shared across contexts
│       ├── Bus/
│       │   ├── InMemoryCommandBus.php
│       │   └── InMemoryQueryBus.php
│       ├── Event/                                 # PSR-14 in-memory dispatcher + listener provider
│       └── Error/
│           └── ExceptionStatusMap.php             # one exception → status mapping
│
├── Subscription/Subscriptions/                    # context / module
│   ├── Domain/
│   │   ├── Subscription.php                       # aggregate root (extends AggregateRoot)
│   │   ├── SubscriptionCreated.php                # domain event
│   │   ├── SubscriptionRepository.php             # port (implements several narrow ifaces)
│   │   ├── SubscriberFinder.php                   # *Finder port
│   │   ├── SubscriberCollection.php
│   │   ├── SubscriberRef.php
│   │   ├── SubscriptionCountPort.php              # *Port (count)
│   │   └── SubscriptionNotFoundException.php
│   ├── Application/
│   │   ├── Subscribe/
│   │   │   ├── SubscribeCommand.php
│   │   │   └── SubscribeCommandHandler.php
│   │   ├── Unsubscribe/
│   │   ├── Find/
│   │   ├── List/
│   │   └── SubscriptionResponseFactory.php
│   └── Infrastructure/
│       └── Persistence/                           # PDO adapters implementing Domain ports
│
├── RepositoryTracking/Repositories/
│   ├── Domain/
│   │   ├── RepositoryStatus.php
│   │   ├── RepositoryStatusReader.php             # *Reader port
│   │   ├── ScanCandidateSource.php                # *Source port
│   │   ├── ScanProgressWriter.php                 # *Writer port
│   │   ├── TrackedRepositoryRegistrar.php         # *Registrar port (cross-context entry)
│   │   ├── RepositoryCountPort.php
│   │   ├── RepositoryChecked.php
│   │   └── ReleaseSeenAdvanced.php
│   ├── Application/
│   └── Infrastructure/
│       ├── Persistence/
│       │   ├── PdoTrackedRepositoryReader.php     # implements Reader/Source/Count ports
│       │   └── PdoTrackedRepositoryWriter.php     # implements Registrar/Writer ports
│       └── Factory/
│
├── Releases/Sourcing/
│   ├── Domain/
│   │   ├── Release.php                            # anemic readonly VO snapshot
│   │   ├── DetectedRelease.php                    # tag guaranteed present
│   │   ├── NewReleaseDetected.php                 # domain event (owned by Releases)
│   │   ├── ReleaseSource.php                      # *Source port
│   │   └── RateLimitException.php
│   ├── Application/
│   │   ├── FetchLatestRelease/                    # Query + Handler + Response
│   │   └── RepositoryExists/
│   └── Infrastructure/
│       ├── GitHubApiReleaseSource.php             # implements ReleaseSource via Guzzle
│       ├── Cache/                                 # Predis-backed GitHub-API cache (decorator)
│       └── Factory/
│
├── Scanning/Scanner/                              # orchestration — NO Domain layer
│   ├── Application/
│   │   ├── ReleaseDetector.php
│   │   └── ScanReleases/                          # ScanReleasesCommand + Handler
│   └── Infrastructure/
│       └── ScannerCliRunner.php                   # CLI driver → CommandBus
│
├── Notification/Publishing/                       # monolith publisher side
│   ├── Domain/
│   │   ├── SendReleaseEmail.php                   # integration message (crosses RabbitMQ)
│   │   ├── ReleaseSnapshot.php                    # decoupled from Releases on purpose
│   │   ├── ReleaseNotificationPublisher.php       # port
│   │   └── SendReleaseEmailFactoryInterface.php
│   ├── Application/
│   │   └── PublishReleaseEmailsForRelease.php     # resolves recipients via Subscription ports
│   └── Infrastructure/
│       ├── RabbitReleaseNotificationPublisher.php # php-amqplib adapter
│       ├── Listener/
│       │   └── PublishReleaseEmailsOnNewReleaseDetectedListener.php
│       ├── Factory/
│       └── Serialization/
│
├── Controller/                                    # Legacy.Infrastructure (drains over Epic B)
│   ├── HealthController.php
│   └── MetricsController.php
├── Grpc/
│   └── ReleaseNotifierService.php                 # RoadRunner gRPC handler (transport only)
└── Migration/
    └── Migrator.php

apps/
├── monolith/{http,grpc,scanner}/                  # placeholder composition-root stubs
└── notification/                                  # FULLY extracted service (own composer.json,
    ├── composer.json                              #   src/, tests/, deptrac.yaml — RabbitMQ-only)
    ├── deptrac.yaml                               # its own Domain/Application/Infrastructure ruleset
    ├── src/
    │   ├── Shared/                                # reuses App\Shared\… FQCNs (independent code)
    │   └── Sending/{Domain,Application,Infrastructure}/
    └── tests/

migrations/
└── 00X_*.sql                                      # raw SQL migrations (NO ORM, NO annotations)
```

## Deptrac Layers (from `deptrac.yaml`)

Each context maps to three deptrac layers (Scanning has no Domain layer):

- `Shared.{Domain,Application,Infrastructure}`
- `Subscription.{Domain,Application,Infrastructure}`
- `RepositoryTracking.{Domain,Application,Infrastructure}`
- `Releases.{Domain,Application,Infrastructure}`
- `Scanning.{Application,Infrastructure}`
- `NotificationPublishing.{Domain,Application,Infrastructure}`
- `Apps.Monolith`, `Apps.Notification`
- `Legacy.Infrastructure` (transitional `src/Controller`, `src/Grpc`, `src/Migration`)

The extracted service in `apps/notification/src` is collected by `Apps.Notification` and additionally governed by its own `apps/notification/deptrac.yaml`.

## Violation Fix Map: Where Files Should Go

When you see a Deptrac violation, use this map to know where to move or refactor the code:

### Domain → Inline validation / Slim HTTP

**Violation**: `…Domain` does inline `filter_var` / `preg_match`, or `uses Psr\Http\Message\…`

| FROM (Wrong)                                                              | TO (Correct)                                                              |
| ------------------------------------------------------------------------ | ------------------------------------------------------------------------ |
| `Subscription.php` constructor calling `filter_var($email, …)`           | Construct `App\Shared\Domain\ValueObject\EmailAddress` — the VO validates |
| `…Domain` importing `ServerRequestInterface` to read input               | Map the request to a Command in the Infrastructure controller            |

**Move validation into the VO**:

```
Subscription.php (Domain)        →   Subscription.php (Domain) — takes EmailAddress VO
└─ filter_var($email)            →   EmailAddress.php (Shared/Domain/ValueObject)
                                     └─ filter_var lives in the VO constructor (self-validating)
```

---

### Domain → PDO / SQL / Predis

**Violation**: `…Domain uses PDO` (or `Predis\Client`, raw SQL strings)

| FROM (Wrong)                                                  | TO (Correct)                                                          |
| ------------------------------------------------------------ | -------------------------------------------------------------------- |
| `RepositoryTracking/…/Domain/*.php` importing `PDO`          | Domain port (`*Reader`/`*Writer`/`*Registrar`) + Infrastructure PDO adapter |

**Move persistence to an adapter**:

```
Domain entity with PDO            →   Domain: TrackedRepositoryRegistrar (port, no PDO)
└─ $pdo->prepare(...)             →   Infrastructure/Persistence/PdoTrackedRepositoryWriter.php
                                      └─ implements the port, uses PDO + raw SQL internally
```

> Redis/Predis is only ever used for the GitHub-API cache and lives in `Releases/Sourcing/Infrastructure/Cache/` — never in any Domain.

---

### Domain → Slim Request/Response or gRPC type

**Violation**: `…Domain uses Psr\Http\Message\…` or `…Domain uses Grpc\ReleaseNotifier\V1\…`

| FROM (Wrong)                                          | TO (Correct)                                                                |
| ---------------------------------------------------- | --------------------------------------------------------------------------- |
| Domain entity/service touching a Request/Response/gRPC message | Slim controller (`src/Controller/…`) or gRPC handler (`src/Grpc/…`) builds a Command/Query and dispatches it on the bus |

**Keep transport in Infrastructure**:

```
Domain reading a Request          →   Infrastructure controller / gRPC handler
└─ $request->getParsedBody()      →   maps wire fields → SubscribeCommand → commandBus->dispatch()
                                      Domain only ever sees VOs / Commands
```

---

### Infrastructure → Application Handler

**Violation**: `…Infrastructure uses App\…\Application\…Handler`

| FROM (Wrong)                                                     | TO (Correct)                                  |
| --------------------------------------------------------------- | --------------------------------------------- |
| An Infrastructure adapter injecting a concrete `…CommandHandler` | Inject `App\Shared\Domain\Bus\Command\CommandBus`, or react via a domain-event listener |

**Refactor to use the bus**:

```
Adapter injecting Handler         →   Adapter injecting CommandBus (Shared.Domain)
└─ ($handler)(new Command())      →   $commandBus->dispatch(new Command())
```

**Better: react to domain events** (PSR-14 in-process plane):

```
Direct call from Infrastructure   →   Aggregate records event via recordThat(...)
                                      Handler dispatches it; an Infrastructure\Listener
                                      (e.g. PublishReleaseEmailsOnNewReleaseDetectedListener)
                                      reacts — Application depends only on its own Domain.
```

## Quick File Placement Checklist

### Domain Layer Files (NO framework / PDO / Predis / amqplib / Slim / gRPC imports!)

```bash
src/{Context}/{Module}/Domain/
├── {EntityName}.php                    # Aggregate roots extend Shared\Domain\Aggregate\AggregateRoot
├── {Concept}.php                       # Anemic readonly VO snapshots (e.g. Release, ReleaseSnapshot)
├── {Entity}{PastAction}.php            # Domain events (SubscriptionCreated, NewReleaseDetected)
├── {Capability}{Role}.php              # Ports: *Reader / *Writer / *Registrar / *Source / *Finder
└── {Specific}Exception.php             # Domain exceptions
```

> Self-validating VOs (`EmailAddress`, `RepositoryName`, `ReleaseTag`) live in `src/Shared/Domain/ValueObject/`. They MAY use `fromString()`-style named constructors. Anemic DTO snapshots are built through a `*FactoryInterface` — no `from*` static methods on them.

### Application Layer Files (CQRS use-cases via the in-house bus)

```bash
src/{Context}/{Module}/Application/
└── {UseCase}/
    ├── {Action}Command.php             # implements Shared\Domain\Bus\Command\Command
    ├── {Action}CommandHandler.php      # implements CommandHandler<...> (+ #[\Override])
    │   # OR for reads:
    ├── {Action}Query.php               # implements Query
    ├── {Action}Handler.php             # implements QueryHandler<...>
    └── {Action}Response.php            # implements Response
```

### Infrastructure Layer Files (adapters implementing Domain/Application ports)

```bash
src/{Context}/{Module}/Infrastructure/
├── Persistence/
│   └── Pdo{Entity}{Role}.php           # PdoTrackedRepositoryWriter.php — implements Domain ports
├── Cache/                              # Predis-backed cache (GitHub-API only)
├── Listener/
│   └── {Action}On{Event}Listener.php     # PSR-14 listeners reacting to domain events
├── Factory/
│   └── {Thing}Factory.php              # implements {Thing}FactoryInterface
└── {Tech}{Port}.php                    # GitHubApiReleaseSource, RabbitReleaseNotificationPublisher

migrations/
└── 00X_{change}.sql                    # raw SQL DDL — NEVER mapping annotations in Domain
```

## Summary: Fix Violation → Move File

| Violation Type                          | Source File                          | Destination                                                       |
| --------------------------------------- | ------------------------------------ | ----------------------------------------------------------------- |
| Domain → inline `filter_var`/regex      | Entity/service validating inline     | Self-validating VO in `src/Shared/Domain/ValueObject/`            |
| Domain → Slim Request/Response or gRPC  | Domain touching a transport type     | Slim controller / gRPC handler in Infrastructure (build Command)  |
| Domain → PDO / SQL / Predis             | Domain importing `PDO`/`Predis`      | Domain port + `Infrastructure/Persistence/Pdo*` adapter           |
| Infrastructure → Handler                | Direct handler injection             | Inject `CommandBus` / use a domain-event Listener                 |
| Cross-context Infrastructure → Infrastructure | One context reaching another's adapters | Route the contract through the other context's **Domain port** |

---

**Remember**: The structure reflects the business domain and the inward dependency rule. Files belong where they conceptually belong, not where the framework or the tool output makes it convenient.
