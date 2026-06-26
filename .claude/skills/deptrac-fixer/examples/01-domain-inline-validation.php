<?php

declare(strict_types=1);

/**
 * Example 1: Fixing Domain → inline validation / Slim HTTP coupling
 *
 * VIOLATION (two shapes of the same lesson):
 *   1. Domain hand-rolls format validation with filter_var / preg_match
 *      instead of using a self-validating Shared VO.
 *   2. Domain imports a transport type to read raw input.
 *
 * Deptrac:
 *   Subscription.Domain must not depend on Shared.Infrastructure
 *     src/Subscription/Subscriptions/Domain/Subscription.php:9
 *       uses Psr\Http\Message\ServerRequestInterface
 *
 * Fix: constructing a self-validating Shared VO (EmailAddress, RepositoryName)
 * IS the validation. The aggregate takes VOs; transport stays in Infrastructure.
 */

// ============================================================================
// BEFORE (WRONG) — inline validation + transport type inside the Domain
// ============================================================================

namespace Example\Subscription\Subscriptions\Domain;

use Psr\Http\Message\ServerRequestInterface; // VIOLATION! transport type in Domain

final class SubscriptionBefore
{
    private string $email;
    private string $repository;

    public function __construct(ServerRequestInterface $request) // VIOLATION!
    {
        /** @var array{email?: string, repository?: string} $body */
        $body = (array) $request->getParsedBody();

        $email = $body['email'] ?? '';
        $repository = $body['repository'] ?? '';

        // VIOLATION! inline filter_var — validation belongs in a self-validating VO
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Invalid email');
        }

        // VIOLATION! inline regex — validation belongs in a self-validating VO
        if (!preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $repository)) {
            throw new \InvalidArgumentException('Invalid repository format');
        }

        $this->email = $email;
        $this->repository = $repository;
    }
}

// ============================================================================
// AFTER (CORRECT) — self-validating Shared VOs; aggregate takes VOs
//
// The three Shared VOs (EmailAddress, RepositoryName, ReleaseTag) own all
// input validation. Constructing the VO is the validation; a failure throws
// App\Shared\Domain\Exception\InvalidArgumentException, which ExceptionStatusMap
// maps to 400 / INVALID_ARGUMENT. Never inline filter_var/regex in services,
// and never reintroduce standalone App\Validation\* validators.
// ============================================================================

namespace Example\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Self-validating value object — this is the REAL shape used by
 * src/Shared/Domain/ValueObject/EmailAddress.php.
 *
 * @psalm-api
 */
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

namespace Example\Shared\Domain\ValueObject;

use App\Shared\Domain\Exception\InvalidArgumentException;

/**
 * Self-validating value object — the REAL shape used by
 * src/Shared/Domain/ValueObject/RepositoryName.php. A self-validating VO MAY
 * use a fromString()-style named constructor; the from* ban only targets anemic
 * DTO snapshots.
 *
 * @psalm-api
 */
final readonly class RepositoryName implements \Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(private string $value)
    {
        if (!(bool) preg_match(self::PATTERN, $this->value)) {
            throw new InvalidArgumentException('Invalid repository format. Expected: owner/repo');
        }
    }

    public function value(): string
    {
        return $this->value;
    }

    public function owner(): string
    {
        return substr($this->value, 0, (int) strpos($this->value, '/'));
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

// ============================================================================
// AGGREGATE — pure Domain, takes VOs, records domain events
// ============================================================================

namespace Example\Subscription\Subscriptions\Domain;

use App\Shared\Domain\Aggregate\AggregateRoot;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\SubscriptionCreated;

/**
 * Not readonly: AggregateRoot owns a mutable event buffer and a readonly child
 * of a non-readonly parent is a PHP fatal. The fields are individually readonly.
 *
 * @psalm-api
 */
final class Subscription extends AggregateRoot
{
    private function __construct(
        private readonly ?int $id,
        private readonly EmailAddress $email,        // already validated by construction
        private readonly RepositoryName $repository, // already validated by construction
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

    public function id(): ?int
    {
        return $this->id;
    }

    public function email(): string
    {
        return (string) $this->email;
    }

    public function repository(): string
    {
        return (string) $this->repository;
    }

    public function createdAt(): string
    {
        return $this->createdAt;
    }
}

// ============================================================================
// APPLICATION — CQRS command + handler. The handler constructs the VOs.
// ============================================================================

namespace Example\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Bus\Command\Command;

/**
 * A command is a plain immutable intent DTO carrying primitive strings off the
 * wire. It does NOT validate — the VOs do.
 *
 * @psalm-api
 */
final readonly class SubscribeCommand implements Command
{
    public function __construct(
        public string $email,
        public string $repository
    ) {
    }
}

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
 * @implements CommandHandler<SubscribeCommand>
 *
 * @psalm-api
 */
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
        // The self-validating VOs ARE the input validation. Email-first
        // construction order preserves which violation a request with two bad
        // fields reports. InvalidArgumentException maps to 400 / INVALID_ARGUMENT.
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

        // PSR-14 in-process plane — listener exceptions propagate (outbox-free).
        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}

// ============================================================================
// INFRASTRUCTURE — transport stays here. A Slim controller maps the request
// to a Command and dispatches it on the bus. Domain never sees a Request.
// ============================================================================

namespace Example\Subscription\Subscriptions\Infrastructure\Http;

use App\Shared\Domain\Bus\Command\CommandBus;
use App\Subscription\Subscriptions\Application\Subscribe\SubscribeCommand;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/** @psalm-api */
final readonly class SubscribeController
{
    public function __construct(private CommandBus $commandBus)
    {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        /** @var array{email?: string, repository?: string} $body */
        $body = (array) $request->getParsedBody();

        // Transport-shape checks (missing fields, non-JSON body) live here.
        // Format validation is the VOs' job, triggered inside the handler.
        $this->commandBus->dispatch(new SubscribeCommand(
            $body['email'] ?? '',
            $body['repository'] ?? ''
        ));

        return $response->withStatus(201);
    }
}

// ============================================================================
// KEY POINTS:
// 1. Validation lives in the self-validating Shared VOs (construct = validate).
// 2. No inline filter_var/regex in Domain or services; no standalone validators.
// 3. The aggregate takes VOs, not raw strings or a Request.
// 4. Transport (Slim Request/Response) is confined to the Infrastructure controller.
// 5. InvalidArgumentException → 400 / INVALID_ARGUMENT via ExceptionStatusMap.
// ============================================================================
