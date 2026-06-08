<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Application\SendReleaseEmailHandler;
use App\Sending\Domain\MessageProcessingStatsRecorder;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Anti-corruption layer between the `notifications.send-email` queue and
 * SendReleaseEmailHandler. Deserializes message bodies, invokes the handler,
 * and translates its outcome into ack/nack/DLQ.
 *
 * Two separate, sequential try/catch blocks — deliberately not merged:
 *
 * 1. Deserialization: a MalformedReleaseEmailMessageException means the message
 *    is fundamentally unprocessable and will fail identically on every retry.
 *    Route straight to the DLQ without consulting the retry bound.
 *
 * 2. Handler invocation: a Throwable here is environmental (e.g. an SMTP
 *    failure) — the message may succeed on a later attempt. Delegate the
 *    retry/DLQ decision to RabbitConsumer::shouldRouteToDlq().
 *
 * Catching Throwable (not Exception) ensures no error from the handler's ports
 * can crash the long-lived consume loop; every failure resolves to ack or nack.
 */
final readonly class SendReleaseEmailConsumer
{
    public const QUEUE = 'notifications.send-email';

    /** Messages are retried up to this many times before being routed to the DLQ. */
    public const MAX_REDELIVERIES = 3;

    public function __construct(
        private RabbitConsumer $consumer,
        private SendReleaseEmailHandler $handler,
        private SendReleaseEmailMessageMapper $mapper,
        private MessageProcessingStatsRecorder $stats,
    ) {
    }

    public function start(): void
    {
        $this->consumer->consume(self::QUEUE, $this->handleDelivery(...));
    }

    public function handleDelivery(AMQPMessage $message): void
    {
        $this->stats->recordConsumed();

        try {
            $releaseEmail = $this->mapper->fromJson($message->getBody());
        } catch (MalformedReleaseEmailMessageException) {
            $this->stats->recordDlq();
            $this->consumer->nack($message, requeue: false);
            return;
        }

        try {
            $this->handler->handle($releaseEmail);
            $this->consumer->ack($message);
        } catch (\Throwable) {
            $this->stats->recordFailed();
            if ($this->consumer->shouldRouteToDlq($message, self::MAX_REDELIVERIES)) {
                $this->stats->recordDlq();
                $this->consumer->nack($message, requeue: false);
            } else {
                $this->consumer->requeueWithRetry($message);
            }
        }
    }
}
