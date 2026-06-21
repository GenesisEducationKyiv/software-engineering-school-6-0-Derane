<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Saga\Enrollment\Domain\EnrollmentSagaStarter;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Clock;
use App\Shared\Domain\TransactionManager;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * @implements CommandHandler<SubscribeCommand>
 * @psalm-api
 */
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private ReleaseSource $gitHubService,
        private TrackedRepositoryRegistrar $trackedRepositories,
        private EventDispatcherInterface $eventDispatcher,
        private Clock $clock,
        private EnrollmentSagaStarter $sagaStarter,
        private TransactionManager $transactionManager
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        // The self-validating VOs ARE the input validation: construction order
        // (email first) preserves which violation a request with two bad
        // fields reports. InvalidArgumentException maps to 400 / INVALID_ARGUMENT
        // in ExceptionStatusMap.
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

        // ONE transaction (T1 + saga start, atomic — FR2/FR3): the subscription
        // INSERT and the saga INSERT commit together, so there is never a
        // subscription without a saga (a crash between the two writes commits
        // neither). create() opens NO inner transaction (a bare INSERT ... ON
        // CONFLICT ... RETURNING + SELECT-fallback on the shared PDO::class), so it
        // naturally enlists in the outer tx the TransactionManager opened; the
        // dup-POST SELECT-fallback also runs inside this tx. start() is the
        // idempotent INSERT ... ON CONFLICT (subscription_id) DO NOTHING, so a
        // duplicate POST starts no second saga. No broker work in the request thread.
        $this->transactionManager->transactional(function () use ($subscription): int {
            $created = $this->repository->create($subscription);
            $id = $created->id();

            // Subscription::id() is ?int: a committed row (insert RETURNING id or the
            // SELECT-fallback id on a dup POST) always has an id. A null here is a
            // programmer error, not a runtime case — narrow ?int -> int explicitly so
            // the saga start receives a non-null int.
            if ($id === null) {
                throw new \RuntimeException('create() returned a subscription with a null id');
            }

            $this->sagaStarter->start($id);

            return $id;
        });

        // Observability domain event (PSR-14) — unchanged. Dispatched after the
        // atomic commit so a log-listener throw cannot roll back a committed
        // subscription; the saga start lives in the write path, not in this listener.
        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
