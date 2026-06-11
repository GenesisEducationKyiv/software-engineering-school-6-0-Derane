<?php

declare(strict_types=1);

/**
 * Example: CQRS with this project's IN-HOUSE bus (NOT Symfony Messenger).
 *
 * The complete flow this file walks through:
 *   WRITE:  Slim controller -> SubscribeCommand -> CommandBus -> SubscribeCommandHandler
 *           -> Subscription aggregate -> SubscriptionRepository (PDO) -> dispatch domain events
 *   READ:   Slim controller -> FetchLatestReleaseQuery -> QueryBus -> FetchLatestReleaseHandler
 *           -> ReleaseSource port -> FetchLatestReleaseResponse
 *
 * Bus contracts (real): App\Shared\Domain\Bus\Command\{Command,CommandBus,CommandHandler}
 *                       App\Shared\Domain\Bus\Query\{Query,QueryBus,QueryHandler,Response}
 * Adapters (real):      App\Shared\Infrastructure\Bus\{InMemoryCommandBus,InMemoryQueryBus}
 */

// ============================================================================
// COMMAND (Application Layer) — write-side intent, immutable DTO
// ============================================================================

namespace Example\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Bus\Command\Command;

/**
 * SubscribeCommand — the INTENT to subscribe an email to a repository.
 *
 * Location (real): src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommand.php
 *
 * Characteristics:
 *   - final readonly, data-only, no behavior
 *   - carries primitives; the handler turns them into self-validating VOs
 *   - implements the empty Command marker — the bus keys on the concrete class
 */
final readonly class SubscribeCommand implements Command
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}

// ============================================================================
// COMMAND HANDLER (Application Layer) — orchestration only, no business rules
// ============================================================================

namespace Example\Subscription\Subscriptions\Application\Subscribe;

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

/**
 * SubscribeCommandHandler — orchestrates the use case; the Domain does the work.
 *
 * Location (real): src/Subscription/Subscriptions/Application/Subscribe/SubscribeCommandHandler.php
 *
 * The @implements generic narrows the command type for Psalm WITHOUT changing
 * the PHP signature (narrowing a parameter in an implementing method is a fatal,
 * so the narrowing is Psalm-only). This is required for 100% types across the bus
 * boundary, not decoration.
 *
 * @implements CommandHandler<SubscribeCommand>
 */
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,            // own-context Domain port
        private ReleaseSource $gitHubService,                  // cross-context Domain port (granted in deptrac.yaml)
        private TrackedRepositoryRegistrar $trackedRepositories, // cross-context Domain port (granted)
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        // 1. Constructing the self-validating VOs IS the input validation.
        //    Order matters: email first preserves which violation a doubly-bad
        //    request reports. A bad value throws InvalidArgumentException -> 400.
        $email = new EmailAddress($command->email);
        $repository = new RepositoryName($command->repository);

        // 2. Cross-context checks via Domain ports (never their adapters).
        if (!$this->gitHubService->repositoryExists($repository)) {
            throw new RepositoryNotFoundException($command->repository);
        }
        $this->trackedRepositories->ensureExists($command->repository);

        // 3. Delegate to the aggregate — it owns the invariant + records the event.
        $subscription = Subscription::subscribe(
            $email,
            $repository,
            $this->clock->now()->format(\DateTimeInterface::ATOM)
        );

        // 4. Persist via the port (Infrastructure detail behind the interface).
        $this->repository->create($subscription);

        // 5. Drain the recorded domain events onto the synchronous PSR-14 plane.
        //    Listener exceptions propagate — that is the deliberate, outbox-free design.
        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}

// ============================================================================
// QUERY + RESPONSE + HANDLER (Application Layer) — read side
// ============================================================================

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Shared\Domain\Bus\Query\Query;

/**
 * FetchLatestReleaseQuery — read-side request, immutable DTO.
 * Location (real): src/Releases/Sourcing/Application/FetchLatestRelease/FetchLatestReleaseQuery.php
 */
final readonly class FetchLatestReleaseQuery implements Query
{
    public function __construct(public string $repository)
    {
    }
}

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\Release;
use App\Shared\Domain\Bus\Query\Response;

/**
 * FetchLatestReleaseResponse — the typed result the query handler returns.
 * Implements the empty Response marker so the bus returns a Response, not mixed.
 * Location (real): src/Releases/Sourcing/Application/FetchLatestRelease/FetchLatestReleaseResponse.php
 */
final readonly class FetchLatestReleaseResponse implements Response
{
    public function __construct(public ?Release $release)
    {
    }
}

namespace Example\Releases\Sourcing\Application\FetchLatestRelease;

use App\Releases\Sourcing\Domain\ReleaseSource;
use App\Shared\Domain\Bus\Query\Query;
use App\Shared\Domain\Bus\Query\QueryHandler;
use App\Shared\Domain\Bus\Query\Response;
use App\Shared\Domain\ValueObject\RepositoryName;

/**
 * FetchLatestReleaseHandler — reads through a Domain port and wraps the result.
 *
 * Two templates carry concrete types across the bus: T narrows the query, R
 * carries the concrete Response back (so call sites get the exact subtype, not
 * mixed). The runtime signature stays on the base Query/Response types.
 *
 * Location (real): src/Releases/Sourcing/Application/FetchLatestRelease/FetchLatestReleaseHandler.php
 *
 * @implements QueryHandler<FetchLatestReleaseQuery, FetchLatestReleaseResponse>
 */
final readonly class FetchLatestReleaseHandler implements QueryHandler
{
    public function __construct(private ReleaseSource $source)
    {
    }

    #[\Override]
    public function __invoke(Query $query): Response
    {
        // Construct the VO (validates the repo name), read through the port, wrap.
        return new FetchLatestReleaseResponse(
            $this->source->getLatestRelease(new RepositoryName($query->repository))
        );
    }
}

// ============================================================================
// THIN DRIVER (Infrastructure Layer) — Slim controller -> bus
// ============================================================================

namespace Example\Subscription\Subscriptions\Infrastructure\Http;

use App\Shared\Domain\Bus\Command\CommandBus;
use App\Shared\Domain\Exception\ValidationException;
use Example\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * SubscriptionController — a THIN Slim driver. It checks transport shape only,
 * builds a Command, and hands it to the bus. No business logic, no domain-format
 * validation (that's the VOs' job inside the handler).
 *
 * Location (real): src/Subscription/Subscriptions/Infrastructure/Http/SubscriptionController.php
 */
final readonly class SubscriptionController
{
    public function __construct(private CommandBus $commandBus)
    {
    }

    public function subscribe(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var array<string, mixed>|null $body */
        $body = json_decode((string) $request->getBody(), true);

        // Transport-level SHAPE check only -> ValidationException (maps to 400).
        if (!is_array($body) || !isset($body['email'], $body['repository'])) {
            throw new ValidationException('email and repository are required');
        }

        // Build the command and dispatch — the bus finds the registered handler.
        $this->commandBus->dispatch(new SubscribeCommand(
            (string) $body['email'],
            (string) $body['repository']
        ));

        return $response->withStatus(201);
    }
}

// ============================================================================
// PORT (Domain Layer) — the contract; an interface, not an implementation
// ============================================================================

namespace Example\Subscription\Subscriptions\Domain;

use App\Subscription\Subscriptions\Domain\Subscription;

/**
 * SubscriptionRepository — a PORT (hexagonal architecture). Declared in Domain,
 * implemented in Infrastructure. Per-consumer ISP: the write/read of the
 * subscription row lives here; subscriber lookups for notification live on a
 * separate narrow SubscriberFinder port.
 *
 * Location (real): src/Subscription/Subscriptions/Domain/SubscriptionRepository.php
 */
interface SubscriptionRepository
{
    public function create(Subscription $subscription): Subscription;

    public function findById(int $id): ?Subscription;

    public function findByEmailAndRepository(string $email, string $repository): ?Subscription;
}

// ============================================================================
// ADAPTER (Infrastructure Layer) — implements the port with PDO + raw SQL
// ============================================================================

namespace Example\Subscription\Subscriptions\Infrastructure\Persistence;

use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use App\Subscription\Subscriptions\Infrastructure\Factory\SubscriptionFactoryInterface;
use PDO;

/**
 * PdoSubscriptionRepository — the ADAPTER. Implements the Domain port using PDO
 * and raw SQL (no ORM). Rows become aggregates through a factory, keeping the
 * mapping out of the Domain.
 *
 * Location (real): src/Subscription/Subscriptions/Infrastructure/Persistence/PdoSubscriptionRepository.php
 */
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
        // Idempotent on the (email, repository) unique key: re-subscribing returns
        // the existing row (RETURNING + ON CONFLICT DO NOTHING).
        $stmt = $this->pdo->prepare(
            'INSERT INTO subscriptions (email, repository) VALUES (:email, :repository)
             ON CONFLICT (email, repository) DO NOTHING
             RETURNING id, email, repository, created_at'
        );
        $stmt->execute(['email' => $subscription->email(), 'repository' => $subscription->repository()]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row !== false) {
            return $this->subscriptionFactory->reconstitute($row);
        }

        return $this->findByEmailAndRepository($subscription->email(), $subscription->repository())
            ?? throw new \RuntimeException('Subscription not found after insert');
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

    #[\Override]
    public function findByEmailAndRepository(string $email, string $repository): ?Subscription
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, email, repository, created_at FROM subscriptions
             WHERE email = :email AND repository = :repository'
        );
        $stmt->execute(['email' => $email, 'repository' => $repository]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->subscriptionFactory->reconstitute($row) : null;
    }
}

// ============================================================================
// HOW THE BUS IS WIRED (config/container.php) + KEY TAKEAWAYS
// ============================================================================

/*
 * HANDLER REGISTRATION (no auto-tagging; explicit in config/container.php):
 *
 *   // The in-house InMemoryCommandBus keys on the concrete Command class:
 *   CommandBus::class => fn (ContainerInterface $c) => new InMemoryCommandBus([
 *       SubscribeCommand::class   => $c->get(SubscribeCommandHandler::class),
 *       UnsubscribeCommand::class => $c->get(UnsubscribeCommandHandler::class),
 *   ]),
 *
 *   QueryBus::class => fn (ContainerInterface $c) => new InMemoryQueryBus([
 *       FetchLatestReleaseQuery::class => $c->get(FetchLatestReleaseHandler::class),
 *   ]),
 *
 *   // DI rule: bind INTERFACES only; alias a second interface to the first to
 *   // share one instance. NEVER use a concrete class as a DI key just to share it:
 *   SubscriptionRepository::class => /* build PdoSubscriptionRepository(...) *\/,
 *   SubscriberFinder::class       => DI\get(SubscriptionRepository::class), // same instance
 *
 * CQRS BENEFITS IN THIS PROJECT:
 *   - Commands = writes; Queries = reads (typed Response, no mixed leak).
 *   - Each handler does ONE thing; easy to unit-test with mocked ports.
 *   - The bus decouples thin drivers (Slim, gRPC, CLI) from handlers.
 *   - Domain-centric: handlers orchestrate, the aggregate holds business logic.
 *
 * ANTI-PATTERNS TO AVOID:
 *   ❌ Business logic / invariants in the handler (belongs in the aggregate)
 *   ❌ Inline filter_var / regex in the handler (construct the Shared VO)
 *   ❌ A handler calling another concrete handler directly (use the bus / events)
 *   ❌ Depending on another context's adapter (depend on its Domain port)
 *   ❌ A driver doing domain-format validation (drivers check transport shape only)
 *
 * CORRECT PATTERN:
 *   ✅ Handler orchestrates; aggregate executes; VOs validate; ports persist.
 *   ✅ #[\Override] on every __invoke; @implements generic for Psalm.
 *   ✅ Domain events drained onto PSR-14 after persistence.
 */
