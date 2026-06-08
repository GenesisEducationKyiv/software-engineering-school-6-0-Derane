<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Shared\Domain\Exception\RepositoryNotFoundException;
use App\Releases\Sourcing\Domain\ReleaseSource;
use App\RepositoryTracking\Repositories\Domain\TrackedRepositoryRegistrar;
use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Shared\Domain\ValueObject\EmailAddress;
use App\Shared\Domain\ValueObject\RepositoryName;
use App\Subscription\Subscriptions\Application\Validation\SubscriptionValidator;
use App\Subscription\Subscriptions\Domain\Subscription;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

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
        private SubscriptionValidator $validator,
        private EventDispatcherInterface $eventDispatcher,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        $this->validator->assertValidSubscription($command->email, $command->repository);

        if (!$this->gitHubService->repositoryExists($command->repository)) {
            throw new RepositoryNotFoundException($command->repository);
        }

        $this->trackedRepositories->ensureExists($command->repository);

        $subscription = Subscription::subscribe(
            new EmailAddress($command->email),
            new RepositoryName($command->repository),
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        );

        $this->repository->create($subscription);

        foreach ($subscription->pullDomainEvents() as $event) {
            $this->eventDispatcher->dispatch($event);
        }

        $this->logger->info('Subscription created', [
            'email' => $command->email,
            'repository' => $command->repository,
        ]);
    }
}
