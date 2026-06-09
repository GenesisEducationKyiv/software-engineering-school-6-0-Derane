<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\Clock;
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
        private Clock $clock
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

        $this->repository->create($subscription);

        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }
    }
}
