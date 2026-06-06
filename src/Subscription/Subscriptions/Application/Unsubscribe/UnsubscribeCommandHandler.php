<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Application\Unsubscribe;

use App\Shared\Domain\Bus\Command\Command;
use App\Shared\Domain\Bus\Command\CommandHandler;
use App\Subscription\Subscriptions\Domain\SubscriptionNotFoundException;
use App\Subscription\Subscriptions\Domain\SubscriptionRepository;
use Psr\Log\LoggerInterface;

/**
 * Write-side: delete a subscription. Ports the legacy unsubscribe logic: 404 when
 * the row is absent (preserved), then delete + log. Records no domain event
 * (SubscriptionDeleted is a future concern).
 *
 * @implements CommandHandler<UnsubscribeCommand>
 *
 * @psalm-api
 */
final readonly class UnsubscribeCommandHandler implements CommandHandler
{
    public function __construct(
        private SubscriptionRepository $repository,
        private LoggerInterface $logger
    ) {
    }

    #[\Override]
    public function __invoke(Command $command): void
    {
        if ($this->repository->findById($command->id) === null) {
            throw new SubscriptionNotFoundException($command->id);
        }

        $this->repository->delete($command->id);
        $this->logger->info('Subscription deleted', ['id' => $command->id]);
    }
}
