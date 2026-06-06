<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Subscribe;

use App\Exception\RepositoryNotFoundException;
use App\Repository\TrackedRepositoryRegistrar;
use App\Service\GitHubServiceInterface;
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
 * Write-side use-case: subscribe an email to a repository. Ports the legacy
 * SubscriptionService::subscribe logic. Validates (preserving the
 * ValidationException -> 400 path), checks repo existence (Releases port) and
 * ensures it is tracked (RepositoryTracking port), builds + persists the
 * aggregate, then pulls and dispatches its recorded domain events on the PSR-14
 * plane (A3). Returns void (CommandBus contract); the id/created_at are read back
 * via a query.
 *
 * @implements CommandHandler<SubscribeCommand>
 *
 * @psalm-api
 */
final readonly class SubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private GitHubServiceInterface $gitHubService,
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
