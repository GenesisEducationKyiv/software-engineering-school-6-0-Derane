# Deptrac Fixer Reference Guide

**Complete reference for understanding and fixing all types of Deptrac architectural violations in this project.**

## Understanding Deptrac Output

### Violation Message Structure

```
[LAYER_A] must not depend on [LAYER_B]
  [FILE_PATH]:[LINE_NUMBER]
    [VIOLATION_TYPE] [DEPENDENCY_DETAILS]
```

**Example**:

```
RepositoryTracking.Domain must not depend on Shared.Infrastructure
  src/RepositoryTracking/Repositories/Domain/TrackedRepository.php:8
    uses PDO
```

### Violation Types

| Type         | Description              | Example                              |
| ------------ | ------------------------ | ------------------------------------ |
| `uses`       | Import statement         | `uses Predis\Client`                 |
| `extends`    | Class inheritance        | `extends PdoRepository`              |
| `implements` | Interface implementation | `implements ServerRequestInterface`  |
| `instanceof` | Type checking            | `if ($x instanceof PDOStatement)`    |
| `static`     | Static method call       | `RedisGitHubCache::connect()`        |

## Complete Fix Patterns

### 1. Domain Layer Violations

#### 1.1 Domain → Inline `filter_var` / regex (or Slim HTTP)

**Violation** (Domain doing its own format validation, or importing a transport type):

```php
namespace App\Subscription\Subscriptions\Domain;

use Psr\Http\Message\ServerRequestInterface; // ❌ transport in Domain

final class Subscription
{
    public function __construct(private string $email)
    {
        // ❌ inline validation belongs in a self-validating VO, not here
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email');
        }
    }
}
```

**Complete Fix**:

```php
// Step 1: Validation lives in the self-validating Shared VO (this already exists)
// src/Shared/Domain/ValueObject/EmailAddress.php
namespace App\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/** @psalm-api */
final readonly class EmailAddress implements \Stringable
{
    public function __construct(private string $value)
    {
        // Constructing the VO IS the validation.
        if (filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Invalid email format');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}

// Step 2: The aggregate takes the VO — no inline validation, no transport import
// src/Subscription/Subscriptions/Domain/Subscription.php
namespace App\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,        // already validated by construction
        private readonly RepositoryName $repository,
        private readonly string $createdAt
    ) {
    }

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
}
```

```php
// Step 3: The Application handler constructs the VOs; InvalidArgumentException → 400
// src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommandHandler.php
$email = new EmailAddress($command->email);          // throws on bad input
$repository = new RepositoryName($command->repository);
```

> Never reintroduce standalone `App\Validation\*` validator classes (they were absorbed into the VOs), and never inline `filter_var`/regex in services. Transport-shape checks (missing fields, non-JSON body) stay in the Infrastructure controller as a `ValidationException`, not in Domain.

#### 1.2 Domain → PDO / SQL / Predis

**Violation**:

```php
namespace App\RepositoryTracking\Repositories\Domain;

use PDO; // ❌ persistence detail in Domain

final class TrackedRepository
{
    public function ensureExists(PDO $pdo, string $fullName): void
    {
        $stmt = $pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }
}
```

**Complete Fix**:

```php
// Step 1: Declare a per-consumer port in Domain (ISP: *Registrar / *Reader / *Writer)
// src/RepositoryTracking/Repositories/Domain/TrackedRepositoryRegistrar.php
namespace App\RepositoryTracking\Repositories\Domain;

/**
 * Port: idempotent registration of a repository in the scan registry.
 *
 * @psalm-api
 */
interface TrackedRepositoryRegistrar
{
    public function ensureExists(string $fullName): void;
}

// Step 2: Implement the PDO adapter in Infrastructure (raw SQL, PostgreSQL)
// src/RepositoryTracking/Repositories/Infrastructure/Persistence/PdoTrackedRepositoryWriter.php
namespace App\RepositoryTracking\Repositories\Infrastructure\Persistence;

use App\RepositoryTracking\Repositories\Domain\ScanProgressWriter;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use PDO;

/** @psalm-api */
final readonly class PdoTrackedRepositoryWriter implements TrackedRepositoryRegistrar, ScanProgressWriter
{
    public function __construct(private PDO $pdo)
    {
    }

    #[\Override]
    public function ensureExists(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO repositories (full_name) VALUES (:full_name) ON CONFLICT (full_name) DO NOTHING'
        );
        $stmt->execute(['full_name' => $fullName]);
    }

    #[\Override]
    public function markChecked(string $fullName): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['repository' => $fullName]);
    }

    #[\Override]
    public function markReleaseSeen(string $fullName, string $tag): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE repositories SET last_seen_tag = :tag, last_checked_at = NOW() WHERE full_name = :repository'
        );
        $stmt->execute(['tag' => $tag, 'repository' => $fullName]);
    }
}
```

```php
// Step 3: Application/Domain consumers depend only on the port
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private TrackedRepositoryRegistrar $trackedRepositories, // ✅ Domain port
        // ...
    ) {}
}
```

> **Schema changes go in raw SQL migrations** (`migrations/00X_*.sql`) — there is no ORM and no mapping annotations anywhere in Domain.
>
> **Predis is only ever the GitHub-API cache.** It lives in `src/Releases/Sourcing/Infrastructure/Cache/` (e.g. `RedisGitHubCache`, behind `SafeGitHubCacheDecorator`). A Predis import in any Domain is always a violation — move it behind a cache port in that context's Infrastructure.

#### 1.3 Domain → Slim Request/Response or gRPC type

**Violation**:

```php
namespace App\Subscription\Subscriptions\Domain;

use ApiPlatform\Metadata\ApiResource;                 // (n/a — we do NOT use API Platform)
use Psr\Http\Message\ServerRequestInterface;          // ❌ transport type in Domain
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest; // ❌ gRPC wire type in Domain
```

**Complete Fix**: keep all transport in Infrastructure. A Slim controller or a RoadRunner gRPC handler maps the wire type into a Command/Query and hands it to the bus; Domain only ever sees VOs and Commands.

```php
// Infrastructure: RoadRunner gRPC handler (transport stays here)
// src/Grpc/ReleaseNotifierService.php
namespace App\Grpc;

use App\Shared\Domain\Bus\Command\CommandBus;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use Grpc\ReleaseNotifier\V1\CreateSubscriptionRequest;
use Grpc\ReleaseNotifier\V1\SubscriptionReply;
use Spiral\RoadRunner\GRPC\ContextInterface;

/** @psalm-api */
final readonly class ReleaseNotifierService
{
    public function __construct(private CommandBus $commandBus)
    {
    }

    // gRPC method names are generated from the proto contract and keep exact casing.
    public function CreateSubscription(ContextInterface $ctx, CreateSubscriptionRequest $request): SubscriptionReply
    {
        // Map the wire type → Command, then dispatch. Domain never sees the request.
        $this->commandBus->dispatch(
            new SubscribeCommand($request->getEmail(), $request->getRepository())
        );

        return new SubscriptionReply();
    }
}
```

```php
// Application command — a plain immutable DTO, no transport coupling
// src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommand.php
namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Bus\Command\Command;

/** @psalm-api */
final readonly class SubscribeCommand implements Command
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}
```

> The JSON shape, gRPC reply, and Behat assertions are public contract (wire-format protection). When you move transport out of Domain, the wire mapping stays byte-for-byte the same — it just lives in the controller/handler where it belongs.

### 2. Infrastructure Layer Violations

#### 2.1 Infrastructure → Application Handler (Direct Call)

**Violation**:

```php
namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease; // (allowed: own-context Application)
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler; // ❌ another context's handler
use App\Releases\Sourcing\Domain\NewReleaseDetected;

final class WhenNewReleaseDetectedListener
{
    public function __construct(
        private SubscribeCommandHandler $handler // ❌ concrete handler, cross-context Application
    ) {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        ($this->handler)(/* ... */);
    }
}
```

**Complete Fix**:

```php
// Option 1: Depend on the in-house CommandBus (Shared.Domain) instead of a concrete handler
namespace App\SomeContext\SomeModule\Infrastructure\Adapter;

use App\Shared\Domain\Bus\Command\CommandBus;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;

final readonly class SomeAdapter
{
    public function __construct(
        private CommandBus $commandBus // ✅ interface, not a concrete handler
    ) {
    }

    public function doWork(string $email, string $repository): void
    {
        $this->commandBus->dispatch(new SubscribeCommand($email, $repository));
    }
}
```

```php
// Option 2 (Preferred for reactions): react to a domain event via a thin Infrastructure listener
// This is the real pattern in src/Notification/Publishing/Infrastructure/Listener/.
namespace App\Notification\Publishing\Infrastructure\Listener;

use App\Notification\Publishing\Application\PublishReleaseEmailsForRelease;
use App\Notification\Publishing\Domain\ReleaseSnapshot;
use App\Releases\Sourcing\Domain\NewReleaseDetected;

/**
 * Thin adapter from the Releases-owned NewReleaseDetected event to the
 * Publishing use-case: maps the event's DetectedRelease into this context's
 * own ReleaseSnapshot (anti-corruption) and delegates.
 *
 * @psalm-api
 */
final readonly class PublishReleaseEmailsOnNewReleaseDetectedListener
{
    public function __construct(private PublishReleaseEmailsForRelease $publishReleaseEmails)
    {
    }

    public function __invoke(NewReleaseDetected $event): void
    {
        $release = $event->detected->release;

        ($this->publishReleaseEmails)($event->repository, new ReleaseSnapshot(
            $event->detected->tag,
            $release->name,
            $release->htmlUrl,
            $release->publishedAt,
            $release->body,
        ));
    }
}
```

> The Infrastructure listener may depend on its **own** Application use-case (allowed) and on another context's **Domain** event/types when an explicit deptrac grant exists (e.g. `NotificationPublishing.Infrastructure → Releases.Domain`). It must NOT depend on another context's **Application** handler. The Application layer stays free of the foreign Domain by doing the event→snapshot mapping in this Infrastructure listener.

#### 2.2 Cross-context Infrastructure → Infrastructure

**Violation**:

```
Subscription.Infrastructure must not depend on RepositoryTracking.Infrastructure
  src/Subscription/.../Infrastructure/SomeAdapter.php:9
    uses App\RepositoryTracking\Repositories\Infrastructure\Persistence\PdoTrackedRepositoryWriter
```

**Fix**: never wire one context's Infrastructure to another's adapter. Depend on the **Domain port** of the other context (a grant for that Domain edge exists in `deptrac.yaml`), and let DI bind the concrete adapter:

```php
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar; // ✅ Domain port

final readonly class SomeAdapter
{
    public function __construct(private TrackedRepositoryRegistrar $registrar) {}
}
```

### 3. Complex Refactoring Scenarios

#### 3.1 Extracting Business Logic from Handler to Domain

**Before (Handler with business logic)**:

```php
namespace App\Subscription\Subscriptions\Application\Subscribe;

final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __invoke(Command $command): void
    {
        // ❌ Validation/business rules scattered in the handler
        if (filter_var($command->email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('bad email');
        }
        if (!preg_match('#^[\w.-]+/[\w.-]+$#', $command->repository)) {
            throw new \InvalidArgumentException('bad repo');
        }
        // ...
    }
}
```

**After (invariants in VOs / aggregate)**:

```php
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private ReleaseSource $gitHubService,
        private TrackedRepositoryRegistrar $trackedRepositories,
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        // ✅ VOs enforce the format invariants; the handler only orchestrates
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

        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event); // PSR-14 in-process plane
        }
    }
}
```

## Debugging Complex Violations

### Multiple Violations in Same File

```bash
make deptrac 2>&1 | grep -A 2 "Subscription.php"
```

Fix order:

1. Remove all framework/persistence/transport imports first
2. Introduce / reuse the necessary Shared VOs and Domain ports
3. Update constructors and methods to take VOs/ports
4. Move adapters to Infrastructure
5. Update tests

### Circular Dependency Suspicions

If fixing one violation introduces another:

```bash
# Check which layers reference the class
make deptrac 2>&1 | grep "ReleaseSource"
```

Solution: usually the class is in the wrong layer entirely, or a contract that should be a Domain port is being imported as a concrete Infrastructure class.

## Automated Fix Scripts

### Quick Domain Cleanup Scan

```bash
# Find framework/persistence/transport imports in any Domain layer
grep -rn "use PDO\|use Predis\|use Psr\\\\Http\|use Grpc\\\\\|use PhpAmqpLib\|use PHPMailer" src/*/*/Domain/ --include="*.php"

# Find inline validation that belongs in a self-validating VO
grep -rn "filter_var\|preg_match" src/*/*/Domain/ --include="*.php"
```

### Verify Clean Domain

```bash
# This should return empty for a pure domain
find src/*/*/Domain -name "*.php" -exec grep -l \
  "use PDO\|use Predis\|use Psr\\\\Http\|use Grpc\\\\\|use PhpAmqpLib\|use PHPMailer\|use Slim\\\\" {} \;
```

## Edge Cases

### When Domain Needs an External Capability

If domain/use-case logic truly needs an external capability (GitHub, mail, queue), define a **port** in Domain and implement the **adapter** in Infrastructure:

```php
// Port in Domain
namespace App\Releases\Sourcing\Domain;

use App\Shared\Domain\ValueObject\RepositoryName;

/** @psalm-api */
interface ReleaseSource
{
    public function repositoryExists(RepositoryName $repository): bool;

    public function getLatestRelease(RepositoryName $repository): ?Release;
}

// Adapter in Infrastructure (uses Guzzle + the Predis-backed cache decorator)
namespace App\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;

final readonly class GitHubApiReleaseSource implements ReleaseSource
{
    #[\Override]
    public function repositoryExists(RepositoryName $repository): bool { /* HTTP call */ }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release { /* HTTP call */ }
}

// Handler injects the interface only
final readonly class FetchLatestReleaseHandler implements QueryHandler
{
    public function __construct(private ReleaseSource $source) {}
}
```

Then bind the interface in DI (bind interfaces only; alias a second interface to share one instance).

### Load-bearing "dead" code

Some classes look unused at runtime but exist so deptrac can resolve a name and keep a rule meaningful — e.g. `src/Shared/.../RabbitConsumer.php`. **Do not delete such code to clear a violation**; deleting it breaks `composer lint` / `make lint`. Fix the real coupling instead.

## Verification Checklist

After fixing violations:

- [ ] `make deptrac` reports `Violations 0`
- [ ] `make notification-deptrac` reports `Violations 0` (if `apps/notification/` was touched)
- [ ] `make test` passes (PHPUnit Unit suite)
- [ ] `make psalm` shows no new errors (errorLevel 1, 100% types)
- [ ] Domain classes have no framework/PDO/Predis/amqplib/Slim/gRPC imports
- [ ] Value Objects validate their invariants; no inline `filter_var`/regex in services
- [ ] Persistence is behind Domain ports + Infrastructure PDO adapters; schema is raw SQL in `migrations/`
- [ ] Transport (Slim Request/Response, gRPC types) is confined to Infrastructure controllers/handlers
- [ ] Handlers only orchestrate; invariants live in VOs/aggregates
- [ ] Events are recorded in aggregates and handled via the PSR-14 in-process plane
- [ ] `deptrac.yaml` / `deptrac.baseline.yaml` unchanged

## Performance Considerations

When fixing violations:

1. Value Objects should be lightweight (a single scalar + validation)
2. Don't over-engineer — not everything needs a VO; the three Shared VOs cover the validated inputs
3. Use `final readonly class` for stateless adapters/VOs/handlers
4. Anemic readonly DTO snapshots are built through a `*FactoryInterface`, never `from*` static methods
5. Cache is for the GitHub API only — never cache Domain state

## Common Mistakes to Avoid

1. **Moving a Domain entity to Application**: loses business-logic encapsulation
2. **Creating a wrapper class in Domain to hide PDO/Predis**: still violates
3. **Importing another context's Infrastructure adapter**: cross only at the Domain port level
4. **Growing the deptrac baseline / widening the ruleset**: the baseline only shrinks
5. **Forgetting `#[\Override]`** on interface-implementation methods
6. **Deleting load-bearing "dead" deptrac-referenced code** (e.g. `RabbitConsumer.php`)

---

**The architecture is the foundation. Respect the inward dependency rule, and it will serve you well.**
