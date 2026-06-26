<?php

declare(strict_types=1);

/**
 * Example: Fixing common deptrac violations (this-project edition).
 *
 * BEFORE / AFTER for the violations you actually hit in this codebase, using
 * THIS project's patterns:
 *   - input validation in self-validating Shared VOs (NOT inline filter_var/regex)
 *   - anemic snapshots built via *FactoryInterface (NOT from* static methods)
 *   - cross-context collaboration via Domain PORTS (NOT another context's adapter)
 *   - thin drivers + the in-house CommandBus/QueryBus (NOT calling handlers directly)
 *   - business logic in aggregates (NOT in handlers)
 *
 * REMEMBER: NEVER edit deptrac.yaml or deptrac.baseline.yaml to "allow" a
 * violation. The baseline only SHRINKS. Fix the code to respect the boundary.
 */

// ============================================================================
// VIOLATION 1: Domain doing inline input validation (framework-ish concern)
// ============================================================================

/*
 * VIOLATION / SMELL:
 *   Domain class performs ad-hoc input validation instead of using the
 *   self-validating Shared VO. (Also flagged in review / by the conventions.)
 *   src/Subscription/Subscriptions/Domain/Subscription.php
 *     inline filter_var(...) in a Domain class
 */

// ❌ WRONG — ad-hoc validation duplicated in the Domain
namespace Example\Bad\Subscription\Subscriptions\Domain;

final class SubscriptionWrong
{
    private string $email;

    public function __construct(string $email)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) { // ❌ inline validation
            throw new \InvalidArgumentException('bad email');
        }
        $this->email = $email;
    }
}

// ✅ CORRECT — depend on the self-validating Shared VO; constructing it IS validation
namespace Example\Good\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;

final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,        // ✅ VO validated itself on construction
        private readonly RepositoryName $repository,
        private readonly string $createdAt
    ) {
    }

    public static function subscribe(EmailAddress $email, RepositoryName $repository, string $createdAt): self
    {
        // No re-validation here — the VOs are already valid by construction.
        return new self(null, $email, $repository, $createdAt);
    }
}

/*
 * The VO is where "valid email" is defined ONCE. Its rejection raises
 * Shared\Domain\Exception\InvalidArgumentException, which ExceptionStatusMap
 * maps to 400 / INVALID_ARGUMENT:
 *
 *   final readonly class EmailAddress implements \Stringable {
 *       public function __construct(private string $value) {
 *           if (filter_var($this->value, FILTER_VALIDATE_EMAIL) === false) {
 *               throw new InvalidArgumentException('Invalid email format');
 *           }
 *       }
 *   }
 *
 * Do NOT reintroduce standalone App\Validation\* validator classes — they were
 * absorbed into the VOs.
 */

// ============================================================================
// VIOLATION 2: Domain depending on PDO (persistence in the Domain layer)
// ============================================================================

/*
 * VIOLATION MESSAGE (shape):
 *   <Context>.Domain must not depend on PDO
 *   src/Releases/Sourcing/Domain/Release.php
 *     uses PDO
 */

// ❌ WRONG — persistence concern inside a Domain snapshot
namespace Example\Bad\Releases\Sourcing\Domain;

use PDO; // ❌ PDO in Domain

final class ReleaseWrong
{
    public static function load(PDO $pdo, int $id): self // ❌ SQL in Domain
    {
        $stmt = $pdo->prepare('SELECT name FROM releases WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return new self();
    }
}

// ✅ CORRECT — pure anemic snapshot in Domain; all SQL/assembly in Infrastructure
namespace Example\Good\Releases\Sourcing\Domain;

final readonly class Release
{
    public function __construct(
        public ?string $tagName,
        public string $name,
        public string $htmlUrl,
        public string $publishedAt,
        public string $body
    ) {
        // No PDO, no SQL — just data.
    }
}

/*
 * The PDO lives in an Infrastructure adapter, which assembles the snapshot via
 * the factory (see VIOLATION 5). The adapter may depend on Shared.Infrastructure
 * and the framework; the Domain may not.
 */

// ============================================================================
// VIOLATION 3: Cross-context Infrastructure dependency
// ============================================================================

/*
 * VIOLATION MESSAGE (shape):
 *   NotificationPublishing.Application must not depend on Subscription.Infrastructure
 *   src/Notification/Publishing/Application/PublishReleaseEmailsForRelease.php
 *
 * Cross-context Infrastructure -> Infrastructure edges are NEVER allowed.
 * Legitimate cross-context edges run through the other context's Domain PORT and
 * must be granted explicitly in deptrac.yaml.
 */

// ❌ WRONG — reaching into another context's PDO adapter
namespace Example\Bad\Notification\Publishing\Application;

use App\Subscription\Subscriptions\Infrastructure\Persistence\PdoSubscriptionRepository; // ❌ another context's adapter

final readonly class PublishReleaseEmailsForReleaseWrong
{
    public function __construct(private PdoSubscriptionRepository $subscriptions) {} // ❌
}

// ✅ CORRECT — depend on the other context's Domain PORT (granted edge)
namespace Example\Good\Notification\Publishing\Application;

use App\Subscription\Subscriptions\Domain\SubscriberFinder;        // ✅ Subscription.Domain port
use App\Shared\Domain\ValueObject\RepositoryName;

final readonly class PublishReleaseEmailsForRelease
{
    public function __construct(private SubscriberFinder $subscribers) {} // ✅ port, not adapter

    public function __invoke(RepositoryName $repository): void
    {
        $recipients = $this->subscribers->findSubscribersByRepository($repository);
        // ... build one SendReleaseEmail integration message per recipient ...
    }
}

/*
 * deptrac.yaml then grants ONLY the port edge (Application -> Subscription.Domain),
 * never an Infrastructure -> Infrastructure edge. The PdoSubscriptionRepository
 * (which implements SubscriberFinder) is wired in config/container.php and aliased
 * so the same instance serves the port.
 */

// ============================================================================
// VIOLATION 4: Infrastructure calling a concrete Application handler directly
// ============================================================================

/*
 * VIOLATION MESSAGE (shape):
 *   Infrastructure must not depend on a concrete Application handler
 *   src/.../Infrastructure/Listener/SomeListener.php
 */

// ❌ WRONG — Infrastructure depending on the concrete handler
namespace Example\Bad\Subscription\Subscriptions\Infrastructure\Listener;

use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommandHandler; // ❌ concrete handler

final readonly class SomeListenerWrong
{
    public function __construct(private SubscribeCommandHandler $handler) {} // ❌ direct dependency
}

// ✅ CORRECT (Option 1) — depend on the in-house CommandBus
namespace Example\Good\Subscription\Subscriptions\Infrastructure\Listener;

use App\Shared\Domain\Bus\Command\CommandBus; // ✅ the bus, not the handler
use Example\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;

final readonly class SomeListener
{
    public function __construct(private CommandBus $commandBus) {}

    public function onSomething(string $email, string $repository): void
    {
        // The bus resolves the registered handler for the command class.
        $this->commandBus->dispatch(new SubscribeCommand($email, $repository));
    }
}

// ✅ CORRECT (Option 2, RECOMMENDED) — record a domain event; react via PSR-14
namespace Example\Good\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Subscription\Subscriptions\Domain\SubscriptionCreated;

final class Subscription extends AggregateRoot
{
    public static function subscribe(/* ... */): self
    {
        $subscription = new self(/* ... */);
        $subscription->recordThat(new SubscriptionCreated(/* email, repository, occurredOn */));

        return $subscription;
    }
}

namespace Example\Good\Subscription\Subscriptions\Infrastructure\Listener;

use App\Subscription\Subscriptions\Domain\SubscriptionCreated;

/**
 * A thin PSR-14 listener reacts to the in-process domain event. It is wired in
 * the ListenerProvider; exceptions propagate synchronously (outbox-free design).
 */
final readonly class WhenSubscriptionCreatedThenLog
{
    public function __invoke(SubscriptionCreated $event): void
    {
        // Log / emit a metric / publish a cross-service message.
    }
}

// ============================================================================
// VIOLATION 5: 'from*' static constructor on an anemic DTO (use a factory)
// ============================================================================

// ❌ WRONG — from* static method assembling the anemic snapshot
namespace Example\Bad\Releases\Sourcing\Domain;

final readonly class ReleaseWithFrom
{
    public function __construct(public ?string $tagName, public string $name) {}

    /** @param array<string, mixed> $payload */
    public static function fromGitHubPayload(array $payload): self // ❌ from* banned on anemic DTOs
    {
        return new self($payload['tag_name'] ?? null, (string) ($payload['name'] ?? ''));
    }
}

// ✅ CORRECT — build the snapshot through a *FactoryInterface, injected as a port
namespace Example\Good\Releases\Sourcing\Infrastructure\Factory;

use App\Releases\Sourcing\Domain\Release;

interface ReleaseFactoryInterface
{
    /** @param array<string, mixed> $payload */
    public function fromGitHubPayload(array $payload): Release;
}

namespace Example\Good\Releases\Sourcing\Infrastructure\Factory;

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

namespace Example\Good\Releases\Sourcing\Infrastructure;

use App\Releases\Sourcing\Domain\Release;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\ValueObject\RepositoryName;
use Example\Good\Releases\Sourcing\Infrastructure\Factory\ReleaseFactoryInterface;

final readonly class GitHubApiReleaseSource implements ReleaseSource
{
    public function __construct(private ReleaseFactoryInterface $releaseFactory) {} // ✅ inject the factory

    #[\Override]
    public function repositoryExists(RepositoryName $repository): bool
    {
        return true; // (illustrative)
    }

    #[\Override]
    public function getLatestRelease(RepositoryName $repository): ?Release
    {
        $payload = []; // ... fetch from the GitHub API client (cached via Predis) ...

        return $payload === [] ? null : $this->releaseFactory->fromGitHubPayload($payload);
    }
}

// NOTE: `fromString()` named constructors are still fine on SELF-VALIDATING VOs
// (EmailAddress, RepositoryName, ReleaseTag). The ban targets anemic DTOs only.

// ============================================================================
// VIOLATION 6: Anemic domain model (not deptrac, but the same architecture rule)
// ============================================================================

// ❌ WRONG — business logic + event minting in the handler; setters on the aggregate
namespace Example\Bad\RepositoryTracking\Repositories\Application;

use App\RepositoryTracking\Repositories\Domain\ReleaseSeenAdvanced;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use Psr\EventDispatcher\EventDispatcherInterface;

/** @implements CommandHandler<\App\Shared\Domain\Bus\Command\Command> */
final readonly class MarkReleaseSeenHandlerWrong implements CommandHandler
{
    public function __construct(private EventDispatcherInterface $eventDispatcher) {}

    #[\Override]
    public function __invoke(Command $command): void
    {
        $status = /* $this->reader->findByFullName(...) */ null;

        // ❌ business rule + event creation in the handler
        // if ($status->getLastSeenTag() === $tag) { return; }
        // $status->setLastSeenTag($tag);
        $this->eventDispatcher->dispatch(new ReleaseSeenAdvanced('owner/repo', 'v1', new \DateTimeImmutable()));
    }
}

// ✅ CORRECT — invariant + event live in the aggregate; the handler orchestrates
namespace Example\Good\RepositoryTracking\Repositories\Domain;

use App\RepositoryTracking\Repositories\Domain\ReleaseSeenAdvanced;
use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\ReleaseTag;

final class RepositoryStatus extends AggregateRoot
{
    private function __construct(private readonly string $fullName, private ?string $lastSeenTag) {}

    public static function existing(string $fullName): self
    {
        return new self($fullName, null);
    }

    public function markReleaseSeen(ReleaseTag $tag): void
    {
        if ($this->lastSeenTag === $tag->value()) {
            return; // ✅ business invariant lives in the aggregate
        }

        $this->lastSeenTag = $tag->value();
        $this->recordThat(new ReleaseSeenAdvanced($this->fullName, $tag->value(), new \DateTimeImmutable()));
    }
}

namespace Example\Good\RepositoryTracking\Repositories\Application;

use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;

/** @implements CommandHandler<\App\Shared\Domain\Bus\Command\Command> */
final readonly class MarkReleaseSeenHandler implements CommandHandler
{
    #[\Override]
    public function __invoke(Command $command): void
    {
        // $status = $this->reader->findByFullName($fullName) ?? RepositoryStatus::existing($fullName);
        // $status->markReleaseSeen(new ReleaseTag($tag)); // ✅ delegate to the aggregate
        // $this->writer->save($status);
        // foreach ($status->pullDomainEvents() as $event) { $this->eventDispatcher->dispatch($event); }
    }
}

// ============================================================================
// COMPLETE WORKFLOW: fixing a violation
// ============================================================================

/*
 * STEP 1: Run deptrac
 *   $ make deptrac
 *
 * STEP 2: Read the violation carefully, e.g.
 *   ---------------------------------------------------------------
 *   Subscription.Domain must not depend on PDO
 *   src/Subscription/Subscriptions/Domain/Subscription.php
 *   ---------------------------------------------------------------
 *
 * STEP 3: Understand the problem
 *   - Subscription is in the Domain layer.
 *   - It is importing PDO (infrastructure).
 *   - Domain must have NO dependencies outward.
 *
 * STEP 4: Plan the refactor (THIS project's patterns)
 *   - Remove the infrastructure import from Domain.
 *   - Move SQL into the PDO adapter (Infrastructure/Persistence).
 *   - Reconstitute the aggregate from rows via a *FactoryInterface.
 *   - For input validation, lean on the self-validating Shared VOs.
 *
 * STEP 5: Refactor the code (move it; do NOT touch deptrac config).
 *
 * STEP 6: Verify the fix
 *   $ make deptrac      # zero new violations beyond the only-shrinking baseline
 *
 * STEP 7: Ensure the gates still pass
 *   $ make test         # Unit suite
 *   $ make psalm        # 100% types (errorLevel 1)
 *   $ make ci           # ends with "✅ CI checks successfully passed!"
 */

// ============================================================================
// KEY PRINCIPLES RECAP (this codebase)
// ============================================================================

/*
 * 1. NEVER EDIT deptrac.yaml / deptrac.baseline.yaml TO BYPASS A VIOLATION
 *    - They define the architecture; the baseline only SHRINKS. Fix the code.
 *
 * 2. LAYER DEPENDENCY RULES (per bounded context)
 *    Domain         -> NOTHING outward (pure PHP + Shared\Domain)
 *    Application    -> own Domain + Shared + granted cross-context Domain PORTS
 *    Infrastructure -> own Domain + Application + Shared.* + the framework
 *    (NO cross-context Infrastructure -> Infrastructure edges, ever.)
 *
 * 3. DOMAIN IS SACRED
 *    - No Slim, PDO, Predis, PHPMailer, php-amqplib, gRPC.
 *    - No SQL, no HTTP, no transport concerns. Only business logic + events.
 *
 * 4. VALIDATION STRATEGY (this codebase)
 *    - Input validation lives in the self-validating Shared VOs (constructing
 *      the VO IS the validation). No framework validator, annotations, or YAML.
 *    - Drivers check transport SHAPE only (ValidationException).
 *    - Domain methods enforce business invariants only.
 *
 * 5. PICK THE RIGHT VALUE TYPE (see 02-value-object-examples.php)
 *    - Self-validating VO for invariant-bearing values (EmailAddress, RepositoryName).
 *    - Anemic readonly DTO (via a factory) for pure data carriers (Release).
 *    - Aggregate for identity + lifecycle (Subscription, RepositoryStatus).
 *
 * 6. USE FACTORIES, NOT from* ON DTOs (in production code)
 *    - Inject a *FactoryInterface; build snapshots / reconstitute rows there.
 *    - `new`/`fromString()` are fine in tests and on self-validating VOs.
 *
 * 7. BUSINESS LOGIC BELONGS IN THE DOMAIN
 *    - Not in handlers (orchestration only), repositories, or drivers.
 *
 * 8. CROSS-LAYER / CROSS-CONTEXT COMMUNICATION
 *    - Use the in-house CommandBus / QueryBus, or record domain events.
 *    - Reach other contexts through their Domain PORTS, never their adapters.
 *
 * 9. DI: BIND INTERFACES ONLY
 *    - Alias a second interface to the first to share one instance.
 *    - Never use a concrete class as a DI key just to share it.
 *
 * 10. PRAGMATIC OVER PURE
 *     - Follow this project's actual conventions; keep it simple (YAGNI).
 */
