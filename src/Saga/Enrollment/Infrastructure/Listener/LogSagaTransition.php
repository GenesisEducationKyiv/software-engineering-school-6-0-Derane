<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Listener;

use App\Saga\Enrollment\Domain\Event\SagaCompensated;
use App\Saga\Enrollment\Domain\Event\SagaCompleted;
use App\Saga\Enrollment\Domain\Event\SagaStarted;
use App\Saga\Enrollment\Domain\Event\WelcomePublished;
use Psr\Log\LoggerInterface;

/**
 * The in-process listener that makes the saga event plane load-bearing: every saga
 * lifecycle transition (started -> published -> completed | compensated) is turned
 * into one correlated log line keyed on sagaId + subscriptionId, so the cross-service
 * funnel is greppable in one place (arch §10) and the use-cases stay free of logging.
 *
 * Best-effort by contract: the shared InMemoryEventDispatcher propagates listener
 * exceptions, and the start path dispatches inside the atomic-start transaction, so a
 * logging failure here must NEVER abort a saga transition — it is swallowed.
 *
 * @psalm-api
 */
final readonly class LogSagaTransition
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(SagaStarted|WelcomePublished|SagaCompleted|SagaCompensated $event): void
    {
        try {
            $this->logger->info('saga transition', [
                'event' => $event->eventName(),
                'saga_id' => $event->sagaId,
                'subscription_id' => $event->subscriptionId,
            ]);
        } catch (\Throwable) {
            // observability only — a logging failure must not change saga disposition
        }
    }
}
