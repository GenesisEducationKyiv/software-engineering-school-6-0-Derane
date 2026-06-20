<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Application\SendWelcomeEmailHandler;
use App\Sending\Application\WelcomeAlreadyFailedException;
use App\Sending\Application\WelcomeInFlightException;
use App\Sending\Application\WelcomeProcessingStatsRecorder;
use App\Sending\Domain\WelcomeNotificationLedger;
use App\Shared\Infrastructure\Messaging\Rabbit\MessageConsumer;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * Anti-corruption layer between the `notifications.welcome-email` queue and
 * {@see SendWelcomeEmailHandler}. Mirrors {@see SendReleaseEmailConsumer}'s
 * three failure paths (poison → DLQ; claim contention → park; environmental →
 * bounded retry/DLQ) plus the HW9 terminal-failure reply branch (FR7):
 *
 * - On {@see WelcomeAlreadyFailedException} the handler has already re-published
 *   the terminal `failed` reply for a row that was already terminally failed —
 *   the consumer just nacks to the DLQ (no retry-bound consult).
 * - When an environmental failure exhausts the retry bound, the consumer asks the
 *   handler to run its terminal branch ({@see SendWelcomeEmailHandler::handleTerminal()}):
 *   persist `terminal_failed_at` BEFORE publishing `failed`, fail-closed on an
 *   unconfirmed publish (the publisher throws → not acked, exits for restart) —
 *   only then nack to the DLQ.
 * - On {@see WelcomeOutcomePublishFailedException} the `failed` reply could not be
 *   confirmed (terminal branch OR AlreadyFailed redelivery). This is a deliberate
 *   fail-closed signal, NOT a transient send failure: the message is left unacked
 *   and the exception re-thrown so the consume loop exits for a supervised restart.
 *   It must be caught BEFORE the generic `\Throwable` arm, otherwise an unconfirmed
 *   re-emit of `failed` would re-enter the transient retry ladder
 *   (burning retry budget on an already-terminal row) instead of exiting cleanly.
 */
final readonly class SendWelcomeEmailConsumer
{
    public const QUEUE = 'notifications.welcome-email';

    /** TTL parking queue retries pass through — see RabbitConnection topology. */
    public const RETRY_QUEUE = 'notifications.welcome-email.retry';

    /** Messages are retried up to this many times before the terminal branch runs. */
    public const MAX_REDELIVERIES = 3;

    public function __construct(
        private MessageConsumer $consumer,
        private SendWelcomeEmailHandler $handler,
        private SendWelcomeEmailMessageMapper $mapper,
        private WelcomeProcessingStatsRecorder $stats,
        private LoggerInterface $logger,
    ) {
    }

    public function start(): void
    {
        $this->consumer->consume(self::QUEUE, $this->handleDelivery(...));
    }

    public function handleDelivery(AMQPMessage $message): void
    {
        $this->record(fn() => $this->stats->recordWelcomeConsumed());

        try {
            $welcomeEmail = $this->mapper->fromJson($message->getBody());
        } catch (MalformedWelcomeEmailMessageException $e) {
            $this->logger->error('Malformed welcome email message routed to DLQ', ['error' => $e->getMessage()]);
            $this->consumer->nack($message, requeue: false);
            return;
        }

        $context = [
            'saga_id' => $welcomeEmail->sagaId,
            'subscription_id' => $welcomeEmail->subscriptionId,
            'repository' => $welcomeEmail->repository->value(),
        ];

        try {
            $this->handler->handle($welcomeEmail);
            $this->consumer->ack($message);
        } catch (WelcomeInFlightException) {
            // Benign contention: park for the lease window WITHOUT burning retry budget.
            $this->consumer->requeueWithoutRetryIncrement(
                $message,
                self::RETRY_QUEUE,
                WelcomeNotificationLedger::CLAIM_LEASE_SECONDS,
            );
            $this->logger->info(
                'Welcome email claim held by another worker — parked for the lease window',
                $context,
            );
        } catch (WelcomeAlreadyFailedException) {
            // The handler already re-published the terminal `failed` reply for an
            // already-terminal row — just complete the DLQ disposition.
            $this->logger->warning('Welcome email already terminally failed — routed to DLQ', $context);
            $this->consumer->nack($message, requeue: false);
        } catch (WelcomeOutcomePublishFailedException $e) {
            // The `failed` reply could not be confirmed by the broker (terminal
            // branch or AlreadyFailed redelivery). Fail closed: leave the delivery
            // unacked and re-throw so bin/consumer.php exits for a supervised
            // restart. The terminal marker is already persisted (handleTerminal
            // markTerminalFailed runs before publish; AlreadyFailed is already
            // terminal), so the redelivery re-emits `failed` without re-sending.
            // Caught BEFORE the generic \Throwable arm so this never re-enters the
            // transient retry ladder.
            $context['error'] = $e->getMessage();
            $this->logger->error(
                'Welcome email outcome reply unconfirmed — exiting for supervised restart',
                $context,
            );
            throw $e;
        } catch (\Throwable $e) {
            $context['error'] = $e->getMessage();
            if ($this->consumer->shouldRouteToDlq($message, self::MAX_REDELIVERIES)) {
                // Retry bound exhausted → terminal branch (FR7): persist
                // terminal_failed_at + publish `failed` (fail-closed) BEFORE the nack.
                $this->handler->handleTerminal($welcomeEmail, $e->getMessage());
                $this->logger->error('Welcome email failed after final retry — routed to DLQ', $context);
                $this->consumer->nack($message, requeue: false);
            } else {
                $this->consumer->requeueWithRetry($message, self::RETRY_QUEUE);
                $this->logger->warning('Welcome email failed — requeued for delayed retry', $context);
            }
        }
    }

    /** Metrics are best-effort: a recorder failure must never crash the consume loop. */
    private function record(callable $record): void
    {
        try {
            $record();
        } catch (\Throwable $e) {
            $this->logger->warning('Failed to record welcome consumer metric', ['error' => $e->getMessage()]);
        }
    }
}
