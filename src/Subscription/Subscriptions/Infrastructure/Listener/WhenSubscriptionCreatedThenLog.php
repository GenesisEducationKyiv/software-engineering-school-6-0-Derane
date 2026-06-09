<?php

declare(strict_types=1);

namespace App\Subscription\Subscriptions\Infrastructure\Listener;

use App\Subscription\Subscriptions\Domain\SubscriptionCreated;
use Psr\Log\LoggerInterface;

/**
 * Observability listener for SubscriptionCreated: the handler emits the
 * domain event, this listener turns it into a log line — same pattern as the
 * HW6 transport/domain-event split. Keeps SubscribeCommandHandler free of
 * logging concerns.
 *
 * @psalm-api
 */
final readonly class WhenSubscriptionCreatedThenLog
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(SubscriptionCreated $event): void
    {
        $this->logger->info('Subscription created', [
            'email' => $event->email,
            'repository' => $event->repository,
        ]);
    }
}
