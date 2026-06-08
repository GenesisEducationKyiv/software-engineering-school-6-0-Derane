<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;

/**
 * D4 cross-deployable copy of the monolith's `App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer`
 * (C4, `src/Shared/Infrastructure/Messaging/Rabbit/RabbitConsumer.php`).
 * `apps/notification` is a standalone deployable with its own composer
 * autoload root (`App\` → `apps/notification/src/`) — it cannot `use` a
 * monolith class across that boundary. Keep both in sync if the shared
 * ack/nack/DLQ contract changes.
 *
 * Generic, **message-shape-agnostic** consumer scaffolding. Exposes the
 * primitives `SendReleaseEmailConsumer` needs:
 *
 * - {@see self::consume()} — registers a callback against a queue
 * - {@see self::ack()} / {@see self::nack()} — generic ack/nack
 * - {@see self::requeueWithRetry()} — publishes a new copy of the message
 *   with an incremented `x-retry-count` application header, then acks the
 *   original. This is the bounded-retry requeue primitive (AC4).
 * - {@see self::shouldRouteToDlq()} — the bounded-retry rule based on the
 *   `x-retry-count` header
 *
 * ## Why `nack(requeue: false)` routes to the DLQ
 *
 * `notifications.send-email` is declared with
 * `x-dead-letter-exchange: notifications.dlx`. A `nack`/`reject` with
 * `requeue: false` dead-letters the message to `notifications.dlx`, which
 * (being a `fanout`) forwards it to `notifications.send-email.dlq`.
 * "Bounded retry → DLQ" is implemented through topology + this nack flag.
 *
 * ## Why `requeueWithRetry` instead of `nack(requeue: true)`
 *
 * RabbitMQ only increments `x-death` records via dead-lettering (nack with
 * `requeue: false`, TTL expiry, or overflow). A plain `nack(requeue: true)`
 * puts the message back on the queue but does **not** add or increment
 * `x-death` — so reading `x-death` to bound retries is always 0 and the
 * check never fires, creating an infinite requeue loop.
 *
 * The fix: track retries in a custom application header `x-retry-count`.
 * {@see self::requeueWithRetry()} publishes a fresh copy with an incremented
 * `x-retry-count` header and acks the original. {@see self::shouldRouteToDlq()}
 * reads that header — the count grows with every call, so the bound fires
 * correctly after `$maxRedeliveries` retries.
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
     * Registers `$callback` against `$queue`.
     *
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

    /** Acknowledges successful processing — the broker permanently removes the message. */
    public function ack(AMQPMessage $message): void
    {
        $message->ack();
    }

    /**
     * Negatively acknowledges processing.
     *
     * - `$requeue = false` → dead-letters the message via the queue's
     *   `x-dead-letter-exchange` (poison messages, or retry-exhausted messages).
     */
    public function nack(AMQPMessage $message, bool $requeue): void
    {
        $message->nack($requeue);
    }

    /**
     * Publishes a new copy of the message with an incremented `x-retry-count`
     * application header to the same exchange/routing-key, then acks the
     * original. This is how bounded retry is implemented — see class docblock.
     */
    public function requeueWithRetry(AMQPMessage $message): void
    {
        $newCount = $this->retryCountFor($message) + 1;
        $newMessage = new AMQPMessage(
            $message->getBody(),
            ['application_headers' => new AMQPTable([self::RETRY_HEADER => $newCount]), 'delivery_mode' => 2],
        );
        $this->connection->channel()->basic_publish(
            $newMessage,
            $message->getExchange(),
            $message->getRoutingKey(),
        );
        $message->ack();
    }

    /**
     * Returns `true` once the message's `x-retry-count` header reaches or
     * exceeds `$maxRedeliveries` — the caller should then route to the DLQ
     * instead of retrying.
     */
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
        $count = $data[self::RETRY_HEADER] ?? 0;

        return is_int($count) ? $count : 0;
    }
}
