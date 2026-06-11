# Deptrac Fixer Examples

This directory contains practical before/after examples for fixing common Deptrac architectural violations using the **actual patterns** of this codebase: Clean Architecture + pragmatic DDD, with the inward dependency rule (`Domain ← Application ← Infrastructure`) enforced per bounded context.

## 🎯 The Patterns

These examples follow the real conventions used across `src/`:

- ✅ **Domain stays pure** — only `Shared.Domain` imports; no Slim/PSR-7, no PDO, no Predis, no php-amqplib, no gRPC types
- ✅ **Validation lives in self-validating Shared VOs** (`EmailAddress`, `RepositoryName`, `ReleaseTag`) — constructing the VO *is* the validation; no inline `filter_var`/regex in services
- ✅ **Persistence behind Domain ports + Infrastructure PDO adapters** — raw SQL migrations, no ORM, no mapping annotations
- ✅ **Transport in Infrastructure** — Slim controllers + RoadRunner gRPC handlers map the wire type to a CQRS Command/Query
- ✅ **In-house CQRS bus** — `CommandBus` / `QueryBus` (NOT Symfony Messenger); thin drivers dispatch, handlers orchestrate

## Examples Overview

### 1. [01-domain-inline-validation.php](01-domain-inline-validation.php)

**Fixing Domain → inline validation / Slim HTTP coupling**

**Key Patterns:**

- ❌ BEFORE: inline `filter_var`/`preg_match` and a `ServerRequestInterface` inside a Domain entity
- ✅ AFTER: self-validating Shared VOs (`EmailAddress`, `RepositoryName`); aggregate takes VOs; transport stays in the Slim controller
- Shows: where validation belongs, `InvalidArgumentException` → 400 via `ExceptionStatusMap`

### 2. [02-domain-pdo-persistence.php](02-domain-pdo-persistence.php)

**Removing PDO / SQL / Predis from the Domain**

**Key Patterns:**

- ❌ BEFORE: `PDO` + raw SQL executed inside a Domain entity
- ✅ AFTER: per-consumer Domain ports (`*Registrar` / `*Writer` / `*Reader`) + Infrastructure PDO adapters (PostgreSQL)
- Shows: per-consumer ISP, `*FactoryInterface` for anemic snapshots, raw SQL migrations, Predis confined to the GitHub-API cache

### 3. [03-domain-transport-coupling.php](03-domain-transport-coupling.php)

**Keeping Slim Request/Response and gRPC types out of the Domain**

**Key Patterns:**

- ❌ BEFORE: `Grpc\…` and `Psr\Http\Message\…` imported into a Domain class
- ✅ AFTER Option 1: RoadRunner gRPC handler maps the wire type → Query → reply
- ✅ AFTER Option 2: Slim REST controller maps the route/request → Query → JSON
- Shows: in-house `QueryBus` (`ask()`), wire-format protection (JSON / gRPC / Behat)

### 4. [04-infrastructure-handler.php](04-infrastructure-handler.php)

**Using the CommandBus / domain events instead of direct handler calls**

**Key Patterns:**

- ❌ BEFORE: Infrastructure injecting a concrete cross-context Application handler
- ✅ AFTER Option 1: depend on the `CommandBus` interface and dispatch
- ✅ AFTER Option 2 (preferred): aggregate records a domain event; a thin `Infrastructure\Listener` reacts on the PSR-14 plane
- Shows: PSR-14 in-process plane vs RabbitMQ integration messages, granted Domain-port edges vs forbidden Application edges

## How to Use These Examples

1. **Identify your violation type** from `make deptrac` (or `make notification-deptrac`) output
2. **Find the matching example** that addresses the violation
3. **Follow the pattern** with your real context/class names
4. **Apply the fix** to your specific code
5. **Verify with** `make deptrac` after each change (reports `Violations 0`)

## Quick Reference

| Violation Pattern                          | Example | Key Solution                                                   |
| ------------------------------------------ | ------- | ------------------------------------------------------------- |
| Domain → inline `filter_var`/regex / Slim HTTP | 01      | Self-validating Shared VO; transport in Infrastructure controller |
| Domain → PDO / SQL / Predis                | 02      | Domain port + Infrastructure PDO adapter; raw SQL migrations  |
| Domain → Slim Request/Response or gRPC type | 03     | Map wire type → Command/Query in controller / gRPC handler    |
| Infrastructure → Application Handler        | 04      | CommandBus dispatch, or domain event + Infrastructure listener |

## Validation Strategy (CRITICAL)

**✅ CORRECT — validation lives in self-validating Shared VOs:**

- Location: `src/Shared/Domain/ValueObject/` (`EmailAddress`, `RepositoryName`, `ReleaseTag`)
- Constructing the VO IS the validation; a failure throws `App\Shared\Domain\Exception\InvalidArgumentException`
- `ExceptionStatusMap` maps that to 400 / `INVALID_ARGUMENT` for both HTTP and gRPC
- Transport-shape checks (missing fields, non-JSON body) stay in the Infrastructure controller as a `ValidationException`

**❌ WRONG:**

```php
// Don't validate inline inside a service or aggregate
final class SubscribeCommandHandler {
    public function __invoke(SubscribeCommand $command): void {
        if (filter_var($command->email, FILTER_VALIDATE_EMAIL) === false) {  // ❌ use the VO!
            throw new \InvalidArgumentException('bad email');
        }
    }
}

// Don't reintroduce standalone validator classes (absorbed into the VOs)
final class EmailValidator { /* ❌ */ }
```

**✅ CORRECT:**

```php
// The VO validates itself; the handler just constructs it
$email = new EmailAddress($command->email);          // throws InvalidArgumentException → 400
$repository = new RepositoryName($command->repository);
```

## Persistence Strategy (CRITICAL)

**✅ CORRECT — Domain port + Infrastructure PDO adapter:**

```php
// Domain port (per-consumer ISP)
interface TrackedRepositoryRegistrar {
    public function ensureExists(string $fullName): void;
}

// Infrastructure adapter (PostgreSQL, raw SQL)
final readonly class PdoTrackedRepositoryWriter implements TrackedRepositoryRegistrar {
    public function __construct(private \PDO $pdo) {}

    #[\Override]
    public function ensureExists(string $fullName): void { /* prepared statement */ }
}
```

**❌ WRONG:**

```php
// Don't import PDO/Predis into Domain
namespace App\RepositoryTracking\Repositories\Domain;
use PDO;                       // ❌
use Predis\Client;            // ❌ (Predis is the GitHub-API cache only)
```

- Schema changes go in raw SQL migrations (`migrations/00X_*.sql`) — there is no ORM and no mapping annotations.
- Predis is used **only** for the GitHub-API cache, in `Releases/Sourcing/Infrastructure/Cache/`.

## Cross-Layer & Cross-Context Communication (CRITICAL)

**✅ CORRECT — use the in-house bus / Domain ports:**

```php
// Infrastructure depends on the bus interface, not a concrete handler
final readonly class SomeAdapter {
    public function __construct(private CommandBus $commandBus) {}  // ✅
}

// Cross-context contracts cross at the Domain port level only
final readonly class SomeAdapter {
    public function __construct(private TrackedRepositoryRegistrar $registrar) {}  // ✅ Domain port
}
```

**❌ WRONG:**

```php
// Don't inject a concrete (cross-context) Application handler
public function __construct(private SubscribeCommandHandler $handler) {}  // ❌

// Don't reach into another context's Infrastructure
use App\RepositoryTracking\...\Infrastructure\Persistence\PdoTrackedRepositoryWriter;  // ❌
```

## Directory Structure Guide

When moving files, consult **[CODELY-STRUCTURE.md](../CODELY-STRUCTURE.md)** for:

- The complete `src/` bounded-context hierarchy and deptrac layers
- WHERE files should go after fixing violations
- File naming conventions per layer (`*Reader`/`*Writer`/`*Registrar`/`*Source`/`*Finder`, `*FactoryInterface`, etc.)

## Testing Your Fixes

After applying any fix:

```bash
# Verify architecture (monolith, and the service if apps/notification/ changed)
make deptrac
make notification-deptrac

# Ensure tests pass
make test

# Check for type issues (errorLevel 1, 100% types)
make psalm

# Full pipeline (ends with "✅ CI checks successfully passed!")
make ci
```

## Key Principles

1. **Keep Domain pure** — only `Shared.Domain` imports
2. **Validate in self-validating VOs** — no inline `filter_var`/regex, no standalone validators
3. **Persistence behind ports** — Domain port + Infrastructure PDO adapter, raw SQL migrations
4. **Transport in Infrastructure** — controllers / gRPC handlers map the wire type to a Command/Query
5. **Use the in-house bus / Domain ports** — never inject concrete cross-context handlers
6. **Never touch `deptrac.yaml` / `deptrac.baseline.yaml`** — fix the code; the baseline only shrinks

## Real Codebase Reference

All examples mirror real patterns:

- **Self-validating VO**: `src/Shared/Domain/ValueObject/EmailAddress.php`, `RepositoryName.php`, `ReleaseTag.php`
- **Domain port + PDO adapter**: `src/RepositoryTracking/Repositories/Domain/TrackedRepositoryRegistrar.php` + `…/Infrastructure/Persistence/PdoTrackedRepositoryWriter.php`
- **Transport boundary**: `src/Grpc/ReleaseNotifierService.php`, `src/Releases/Sourcing/Application/FetchLatestRelease/`
- **Domain events + listener**: `src/Notification/Publishing/Infrastructure/Listener/WhenNewReleaseDetectedThenPublishReleaseEmails.php` + `…/Application/PublishReleaseEmailsForRelease.php`

**Remember**: these are not theoretical examples — they reflect the actual patterns used in this codebase. Follow them closely to maintain consistency.
