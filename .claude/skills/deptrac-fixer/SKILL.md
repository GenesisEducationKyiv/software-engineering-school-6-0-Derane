---
name: deptrac-fixer
description: Diagnose and fix Deptrac architectural violations automatically. Use when Deptrac reports dependency violations, layers are incorrectly coupled, or when refactoring code to respect Clean Architecture / bounded-context boundaries. Never modifies deptrac.yaml or deptrac.baseline.yaml - always fixes the code to match the architecture.
---

# Deptrac Fixer Skill

## Context (Input)

- `make deptrac` (monolith) or `make notification-deptrac` (the extracted service) reports violations
- Error message contains "must not depend on"
- Domain layer has framework/infrastructure imports (Slim/PSR-7 HTTP, RoadRunner gRPC, PDO, Predis, php-amqplib, PHPMailer)
- Domain does inline `filter_var` / `preg_match` validation instead of using a self-validating VO
- Infrastructure directly calls Application handlers instead of going through the CQRS bus
- A context's Infrastructure reaches into another context's Infrastructure
- Any architectural boundary violation detected

## Task (Function)

Diagnose and fix Deptrac violations by refactoring code to respect the inward dependency rule (Domain ← Application ← Infrastructure) and bounded-context boundaries.

**Success Criteria**: `make deptrac` (and `make notification-deptrac` when the change touches `apps/notification/`) reports `Violations 0`.

---

## Core Principle

**Fix the code, NEVER modify `deptrac.yaml` or `deptrac.baseline.yaml`**

The architecture is correct. The code must conform to it, not vice versa. The baseline (`deptrac.baseline.yaml`) only ever **shrinks** as legacy code drains — never grow it to hide a new violation. Legitimate cross-context *port* edges are granted explicitly in `deptrac.yaml`'s ruleset with a justifying comment; adding such a grant is an architecture decision, not a "fix", and is out of scope for this skill unless the user explicitly opts in.

---

## Quick Start: Fix a Violation

### Step 1: Run Deptrac

```bash
make deptrac
# when the change is in apps/notification/:
make notification-deptrac
```

### Step 2: Parse Violation Message

```
Subscription.Domain must not depend on Shared.Infrastructure
  src/Subscription/Subscriptions/Domain/Subscription.php:8
    uses Predis\Client
```

**Extract:**

- **Violating Layer**: Subscription.Domain
- **Forbidden Dependency**: Predis\Client (Shared.Infrastructure-class / outward dep)
- **File & Line**: `src/Subscription/Subscriptions/Domain/Subscription.php:8`
- **Violation Type**: `uses` (import statement)

### Step 3: Identify Fix Pattern

| Domain Depends On                          | Fix Pattern                                   | Example File                                                                          |
| ------------------------------------------ | --------------------------------------------- | ------------------------------------------------------------------------------------- |
| Inline `filter_var` / regex / Slim HTTP    | Move validation into a self-validating Shared VO | [01-domain-inline-validation.php](examples/01-domain-inline-validation.php)         |
| PDO / SQL / Predis client                  | Domain/Application port + Infrastructure PDO adapter | [02-domain-pdo-persistence.php](examples/02-domain-pdo-persistence.php)        |
| Slim `Request`/`Response` or gRPC type     | Keep transport in Infrastructure controllers/gRPC handlers | [03-domain-transport-coupling.php](examples/03-domain-transport-coupling.php) |
| Infrastructure → Application Handler        | Use CommandBus / domain events                | [04-infrastructure-handler.php](examples/04-infrastructure-handler.php)               |

**See**: [REFERENCE.md](REFERENCE.md) for complete fix patterns and advanced scenarios.

### Step 4: Apply Fix

Follow the pattern from examples, then verify:

```bash
make deptrac
```

Repeat until: `Violations 0`

---

## Layer Dependency Rules

```
Domain ─────────────> Shared.Domain only (NO Application, NO Infrastructure, NO framework)
           │
           │
Application ────────> own Domain + Shared.{Domain,Application} (+ granted cross-context Domain ports)
           │
           │
Infrastructure ─────> own Domain + own Application + Shared.{Domain,Application,Infrastructure} (+ granted cross-context Domain edges)
```

**Allowed Dependencies (from `deptrac.yaml`):**

| Layer              | Can Depend On                                                                 |
| ------------------ | ---------------------------------------------------------------------------- |
| **Domain**         | ✅ `Shared.Domain` only — pure PHP otherwise (no Slim, no PDO, no Predis, no amqplib) |
| **Application**    | ✅ own Domain, `Shared.Domain`, `Shared.Application` + explicitly granted cross-context **Domain** ports |
| **Infrastructure** | ✅ own Domain, own Application, `Shared.{Domain,Application,Infrastructure}` + explicitly granted cross-context **Domain** edges |

> `Shared.Domain` depends on nothing outside itself. `Shared.Application` may depend on `Shared.Domain` (and a few context Domains for `ExceptionStatusMap` / metrics ports — already granted). No context's Infrastructure may reach another context's Infrastructure: cross-context contracts cross only at the **Domain port** level.

**See**: [CODELY-STRUCTURE.md](CODELY-STRUCTURE.md) for the complete directory hierarchy.

---

## Common Fix Patterns (Quick Reference)

### Pattern 1: Domain → Inline `filter_var` / regex (or Slim HTTP)

❌ **Problem**: Format validation hand-rolled inside a Domain entity/service, or an HTTP type imported into Domain

```php
namespace App\Subscription\Subscriptions\Domain;

final class Subscription
{
    public function __construct(private string $email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {  // ❌ inline validation in Domain
            throw new \InvalidArgumentException('Invalid email');
        }
    }
}
```

✅ **Solution**: Construct a self-validating Shared VO — constructing the VO *is* the validation

```php
use App\Shared\Domain\ValueObject\EmailAddress;

$email = new EmailAddress($command->email); // throws Shared InvalidArgumentException → 400
```

---

### Pattern 2: Domain → PDO / SQL / Predis

❌ **Problem**: Persistence/cache client imported into Domain

```php
namespace App\RepositoryTracking\Repositories\Domain;

use PDO; // ❌ Domain must not know about PDO

final class TrackedRepository
{
    public function save(PDO $pdo): void { /* ... */ }
}
```

✅ **Solution**: Declare a per-consumer port in Domain; implement the PDO adapter in Infrastructure

```php
// Domain (port)
interface TrackedRepositoryRegistrar { public function ensureExists(string $fullName): void; }

// Infrastructure (adapter)
final readonly class PdoTrackedRepositoryWriter implements TrackedRepositoryRegistrar { /* uses PDO */ }
```

---

### Pattern 3: Domain → Slim Request/Response or gRPC type

❌ **Problem**: A transport type imported into Domain

```php
namespace App\Subscription\Subscriptions\Domain;

use Psr\Http\Message\ServerRequestInterface; // ❌ transport type in Domain
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest; // ❌
```

✅ **Solution**: Keep transport in Infrastructure controllers / gRPC handlers; they build a Command/Query and hand it to the bus

```php
// Infrastructure (Slim controller / gRPC handler) maps the wire type into a Command, then:
$this->commandBus->dispatch(new SubscribeCommand($email, $repository));
```

---

### Pattern 4: Infrastructure → Application Handler

❌ **Problem**: Direct handler call from Infrastructure

```php
final readonly class SomeAdapter
{
    public function __construct(
        private SubscribeCommandHandler $handler  // ❌ concrete Application handler in Infrastructure
    ) {}
}
```

✅ **Solution**: Depend on the in-house `CommandBus` interface (or react via a domain-event listener)

```php
final readonly class SomeAdapter
{
    public function __construct(
        private CommandBus $commandBus  // ✅ Shared.Domain interface
    ) {}

    public function someMethod(): void
    {
        $this->commandBus->dispatch(new SubscribeCommand($email, $repo));
    }
}
```

**See**: [examples/](examples/) directory for complete, runnable examples.

---

## Diagnostic Workflow

When facing multiple violations:

### Step 1: Get All Violations

```bash
make deptrac > violations.txt 2>&1
```

### Step 2: Categorize by Type

Group violations by layer pair:

- Domain → inline validation / Slim HTTP
- Domain → PDO / Predis
- Domain → gRPC / Request-Response
- Infrastructure → Application handler (direct)
- Infrastructure → another context's Infrastructure
- etc.

### Step 3: Fix in Priority Order

1. **Domain violations first** (most critical — Domain must stay pure)
2. **Cross-context Infrastructure violations** (route contracts through Domain ports)
3. **Infrastructure → Application** (use the CQRS bus / domain events)

### Step 4: Verify Incrementally

```bash
# After each fix
make deptrac
```

Track progress: 15 violations → 10 → 5 → 0 ✅

---

## Constraints (Parameters)

### NEVER

- Modify `deptrac.yaml` to allow violations
- Add to / regenerate `deptrac.baseline.yaml` to hide a new violation (the baseline only shrinks)
- Disable Deptrac checks
- Add suppression comments or ignore directives (`@psalm-suppress`, `phpcs:ignore`, `phpcs:disable`)
- Create "wrapper" classes to hide dependencies
- Move an entire class to the wrong layer just to satisfy Deptrac
- Move classes into unrelated/random directories just to make Deptrac pass (for example, hiding a `Factory` under `Persistence`)
- Brute-force Deptrac by reorganizing code around tool output instead of business responsibility
- Create ad-hoc directory/class types; use only well-known software patterns (Factory, Adapter, Decorator, Listener, CQRS Command/Query + Handler, per-consumer port)
- Delete code that looks dead just to clear a violation without checking — some classes (e.g. `src/Shared/.../RabbitConsumer.php`) are dead at runtime but **load-bearing for deptrac**; removing them breaks `make lint` / `composer lint`
- Use reflection or dynamic loading to bypass checks

### ALWAYS

- Fix the code to match the architecture
- Keep Domain pure: only `Shared.Domain` imports, no framework/persistence/transport
- Use interfaces (ports) for cross-layer and cross-context dependencies — cross-context contracts cross only at the Domain port level
- Push input validation into the self-validating Shared VOs (`EmailAddress`, `RepositoryName`, `ReleaseTag`) — constructing the VO *is* the validation
- Bind interfaces only in DI; alias a second interface to share one instance
- Put `#[\Override]` on every interface-implementation method
- Verify fixes with `make deptrac` (and `make notification-deptrac` for service changes) after each change
- Check that tests still pass after refactoring (`make test`)

---

## Format (Output)

### Expected Deptrac Output

```
 -------------------- -----
  Report
 -------------------- -----
  Violations           0
  Skipped violations   0
  ...
 -------------------- -----
```

### Expected CI Output

```
✅ CI checks successfully passed!
```

---

## Verification Checklist

After fixing violations:

- [ ] `make deptrac` reports `Violations 0`
- [ ] `make notification-deptrac` reports `Violations 0` (if `apps/notification/` was touched)
- [ ] Domain entities/VOs have no framework, PDO, Predis, amqplib, Slim or gRPC imports
- [ ] Input validation lives in self-validating Shared VOs, not inline `filter_var`/regex in services
- [ ] Persistence is reached through a Domain/Application port + Infrastructure PDO adapter
- [ ] Transport (Slim Request/Response, gRPC types) stays in Infrastructure controllers/handlers
- [ ] Infrastructure uses the `CommandBus`/`QueryBus`, not direct handler calls
- [ ] `deptrac.yaml` / `deptrac.baseline.yaml` unchanged
- [ ] All tests still pass (`make test`)
- [ ] `make ci` passes completely (ends with `✅ CI checks successfully passed!`)

---

## Related Skills

- [implementing-ddd-architecture](../implementing-ddd-architecture/SKILL.md) - Understanding DDD patterns and layer responsibilities
- [code-organization](../code-organization/SKILL.md) - Directory structure, naming, and file placement
- [quality-standards](../quality-standards/SKILL.md) - Overview of protected quality gates
- [testing-workflow](../testing-workflow/SKILL.md) - Running unit/integration/acceptance suites after a refactor
- [ci-workflow](../ci-workflow/SKILL.md) - Running the full CI pipeline before claiming done

---

## Quick Commands

```bash
# Run Deptrac analysis (monolith)
make deptrac

# Run Deptrac analysis (extracted notification service)
make notification-deptrac

# Verify architecture after fixes
make deptrac && make ci
```

---

## Reference Documentation

For detailed patterns, examples, and troubleshooting:

- **[REFERENCE.md](REFERENCE.md)** - Complete fix patterns for all violation types
- **[CODELY-STRUCTURE.md](CODELY-STRUCTURE.md)** - Directory hierarchy and file placement rules
- **[examples/](examples/)** - Complete, runnable code examples:
  - `01-domain-inline-validation.php` - Moving inline validation into a self-validating Shared VO
  - `02-domain-pdo-persistence.php` - Removing PDO/SQL/Predis from Domain via a port + PDO adapter
  - `03-domain-transport-coupling.php` - Keeping Slim/gRPC transport out of Domain
  - `04-infrastructure-handler.php` - Using the CommandBus / domain events instead of direct handler calls

---

## Anti-Patterns to Avoid

### ❌ DON'T Modify deptrac.yaml or the baseline

```yaml
# ❌ NEVER DO THIS — hiding violations by widening the ruleset or growing the baseline
Subscription.Domain:
  - Shared.Domain
  - Shared.Infrastructure   # ❌ Domain must never reach Infrastructure
```

### ❌ DON'T Create Wrapper Classes

```php
// ❌ BAD: Hiding a PDO dependency behind a Domain-located wrapper
namespace App\Subscription\Subscriptions\Domain;
final class SubscriptionStore { private \PDO $pdo; } // Still violates!
```

### ❌ DON'T Move Classes to the Wrong Layer

```php
// ❌ BAD: Moving a Domain entity to Application to "fix" a violation
// src/Subscription/Subscriptions/Application/Subscription.php  // WRONG LAYER!
```

### ✅ DO Fix the Root Cause

- Move validation into a self-validating Shared VO
- Declare a per-consumer port in Domain, implement the adapter in Infrastructure
- Keep transport in Infrastructure controllers/gRPC handlers
- Use the in-house CommandBus/QueryBus and dependency inversion
- Respect layer and bounded-context responsibilities

---

## Success Criteria Summary

- ✅ Zero Deptrac violations (`make deptrac` and, if relevant, `make notification-deptrac`)
- ✅ Domain pure (no framework/persistence/transport imports)
- ✅ Validation in self-validating Shared VOs
- ✅ Persistence/cache behind Domain ports + Infrastructure adapters
- ✅ Proper use of CommandBus/QueryBus for cross-layer communication
- ✅ `deptrac.yaml` / `deptrac.baseline.yaml` untouched
- ✅ All tests passing
- ✅ CI pipeline green
