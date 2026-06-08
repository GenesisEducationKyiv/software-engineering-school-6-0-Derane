<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * Generic, message-shape-agnostic consumer scaffolding over a RabbitMQ channel.
 *
 * ## Why nack(requeue: false) routes to the DLQ
 *
 * `notifications.send-email` is declared with
 * `x-dead-letter-exchange: notifications.dlx`. A nack with `requeue: false`
 * dead-letters the message to `notifications.dlx`, which (being a fanout)
 * forwards it unconditionally to `notifications.send-email.dlq`.
 *
 * ## Why requeueWithRetry instead of nack(requeue: true)
 *
 * RabbitMQ only increments `x-death` records via dead-lettering (nack with
 * requeue: false, TTL expiry, or queue overflow). A plain nack(requeue: true)
 * requeues the message but never increments `x-death`, so any retry bound
 * that reads `x-death` would never fire — producing an infinite retry loop.
 *
 * The fix: track retry count in a custom `x-retry-count` application header.
 * `requeueWithRetry()` publishes a new copy with an incremented count and
 * acks the original. `shouldRouteToDlq()` reads that header — the count
 * grows correctly with each retry.
 *
 * @psalm-api
 */
final readonly class RabbitConsumer
{
    private const RETRY_HEADER = 'x-retry-count';

    public function __construct(private RabbitConnection $connection)
    {
    }

    /**
     * @param callable(AMQPMessage): void $callback
     */
    public function consume(string $queue, callable $callback): void
    {
        $this->connection->channel()->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $callback
        );
    }

    public function ack(AMQPMessage $message): void
    {
        $message->ack();
    }

    public function nack(AMQPMessage $message, bool $requeue): void
    {
        $message->nack($requeue);
    }

    public function requeueWithRetry(AMQPMessage $message): void
    {
        $channel = $this->connection->channel();
        $channel->confirm_select();
        // Must register the nack handler BEFORE publishing. In php-amqplib,
        // wait_for_pending_acks() removes nacked messages from its tracking
        // map and calls the nack_handler — it does NOT throw on its own.
        // Without this handler, a broker nack silently completes the wait and
        // the original is acked, dropping the notification permanently.
        $channel->set_nack_handler(function (): void {
            throw new \RuntimeException(
                'Broker nacked retry publish — original message remains unacked for redelivery.',
            );
        });

        $newCount = $this->retryCountFor($message) + 1;
        $newMessage = new AMQPMessage(
            $message->getBody(),
            ['application_headers' => new AMQPTable([self::RETRY_HEADER => $newCount]), 'delivery_mode' => 2],
        );
        $channel->basic_publish(
            $newMessage,
            $message->getExchange() ?? '',
            $message->getRoutingKey() ?? '',
        );
        $channel->wait_for_pending_acks(5.0);
        $message->ack();
    }

    public function shouldRouteToDlq(AMQPMessage $message, int $maxRedeliveries): bool
    {
        return $this->retryCountFor($message) >= $maxRedeliveries;
    }

    private function retryCountFor(AMQPMessage $message): int
    {
        if (!$message->has('application_headers')) {
            return 0;
        }

        $headers = $message->get('application_headers');
        if (!$headers instanceof AMQPTable) {
            return 0;
        }

        /** @var array<string, mixed> $data */
        $data = $headers->getNativeData();

        return isset($data[self::RETRY_HEADER]) && is_int($data[self::RETRY_HEADER])
            ? $data[self::RETRY_HEADER]
            : 0;
    }
}
