<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Application\MessageProcessingStatsRecorder;
use App\Sending\Application\NotificationInFlightException;
use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Domain\NotificationLedger;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Anti-corruption layer between the `notifications.send-email` queue and
 * SendReleaseEmailHandler. Deserializes message bodies, invokes the handler,
 * and translates its outcome into ack/nack/DLQ.
 *
 * Three distinct failure paths — deliberately not merged:
 *
 * 1. Deserialization: a MalformedReleaseEmailMessageException means the message
 *    is fundamentally unprocessable and will fail identically on every retry.
 *    Route straight to the DLQ without consulting the retry bound.
 *
 * 2. Claim contention: NotificationInFlightException means another worker
 *    holds a live ledger claim. Contention is not failure — the message is
 *    parked for the full claim lease WITHOUT consuming retry budget (the
 *    retry schedule (~35s) is far shorter than the lease (300s); burning
 *    retries against a claim that was always going to expire would DLQ a
 *    perfectly deliverable notification).
 *
 * 3. Anything else is environmental (e.g. an SMTP failure) — the message may
 *    succeed on a later attempt. Delegate the retry/DLQ decision to
 *    RabbitConsumer::shouldRouteToDlq().
 *
 * Catching Throwable (not Exception) ensures no error from the handler's ports
 * can crash the long-lived consume loop; every failure resolves to ack or nack.
 */
final readonly class SendReleaseEmailConsumer
{
    public const QUEUE = 'notifications.send-email';

    /** TTL parking queue retries pass through — see RabbitConnection topology. */
    public const RETRY_QUEUE = 'notifications.send-email.retry';

    /** Messages are retried up to this many times before being routed to the DLQ. */
    public const MAX_REDELIVERIES = 3;

    public function __construct(
        private MessageConsumer $consumer,
        private SendReleaseEmailHandler $handler,
        private SendReleaseEmailMessageMapper $mapper,
        private MessageProcessingStatsRecorder $stats,
        private LoggerInterface $logger,
    ) {
    }

    public function start(): void
    {
        $this->consumer->consume(self::QUEUE, $this->handleDelivery(...));
    }

    public function handleDelivery(AMQPMessage $message): void
    {
        $this->record(fn() => $this->stats->recordConsumed());

        try {
            $releaseEmail = $this->mapper->fromJson($message->getBody());
        } catch (MalformedReleaseEmailMessageException $e) {
            $this->logger->error('Malformed release email message routed to DLQ', ['error' => $e->getMessage()]);
            $this->record(fn() => $this->stats->recordDlq());
            $this->consumer->nack($message, requeue: false);
            return;
        }

        $context = [
            'event_id' => $releaseEmail->eventId,
            'subscription_id' => $releaseEmail->subscriptionId,
            'repository' => $releaseEmail->repository->value(),
            'tag' => $releaseEmail->tagName->value(),
        ];

        try {
            $this->handler->handle($releaseEmail);
            $this->consumer->ack($message);
        } catch (NotificationInFlightException) {
            $this->record(fn() => $this->stats->recordContention());
            // Log only AFTER the park actually succeeds — a fail-closed
            // RetryPublishFailedException here must not leave a "parked" log behind.
            $this->consumer->requeueWithoutRetryIncrement(
                $message,
                self::RETRY_QUEUE,
                NotificationLedger::CLAIM_LEASE_SECONDS,
            );
            $this->logger->info(
                'Release email claim held by another worker — parked for the lease window',
                $context,
            );
        } catch (\Throwable $e) {
            $this->record(fn() => $this->stats->recordFailed());
            $context['error'] = $e->getMessage();
            if ($this->consumer->shouldRouteToDlq($message, self::MAX_REDELIVERIES)) {
                $this->logger->error('Release email failed after final retry — routed to DLQ', $context);
                $this->record(fn() => $this->stats->recordDlq());
                $this->consumer->nack($message, requeue: false);
            } else {
                // Log only AFTER the requeue is confirmed — see the park branch above.
                $this->consumer->requeueWithRetry($message, self::RETRY_QUEUE);
                $this->logger->warning('Release email failed — requeued for delayed retry', $context);
            }
        }
    }

    /** Metrics are best-effort: a recorder failure must never crash the consume loop. */
    private function record(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record consumer metric', ['error' => $e->getMessage()]);
        }
    }
}
