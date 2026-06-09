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
 * ## Why retries go through a TTL retry queue (backoff)
 *
 * Republishing straight back to the work queue retries in milliseconds — an
 * SMTP outage would burn every attempt instantly and park the message in the
 * DLQ. Instead the copy is published to a retry queue (no consumers) with a
 * per-message `expiration` that grows with the retry count; on expiry the
 * broker dead-letters it back into the work queue. Per-message TTL only
 * expires from the queue head, but because the delay grows monotonically
 * with the retry count, FIFO order matches expiry order closely enough here.
 *
 * @psalm-api
 */
final readonly class RabbitConsumer
{
    private const RETRY_HEADER = 'x-retry-count';

    /** First retry delay; doubles with each subsequent attempt (5s, 10s, 20s…). */
    private const BASE_RETRY_DELAY_SECONDS = 5;

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

    /**
     * Publishes a delayed copy of $message to $retryQueue (via the default
     * exchange) with an incremented retry count, then acks the original.
     * Original message properties (content_type, …) are preserved; only the
     * retry header, persistence and the per-message TTL are overridden.
     */
    public function requeueWithRetry(AMQPMessage $message, string $retryQueue): void
    {
        $newCount = $this->retryCountFor($message) + 1;
        $this->republishDelayed($message, $retryQueue, $newCount, $this->retryDelaySeconds($newCount));
    }

    /**
     * Parks the message for $delaySeconds WITHOUT consuming retry budget —
     * the retry count is carried over unchanged. For backoff that is not
     * failure, e.g. waiting out another worker's claim lease.
     *
     * Per-message TTL only expires from the queue head, so a long park can
     * delay shorter retries queued behind it — acceptable because parking
     * only happens on claim contention, which needs a concurrent worker or
     * a crashed predecessor to occur at all.
     */
    public function requeueWithoutRetryIncrement(AMQPMessage $message, string $retryQueue, int $delaySeconds): void
    {
        $this->republishDelayed($message, $retryQueue, $this->retryCountFor($message), $delaySeconds);
    }

    private function republishDelayed(
        AMQPMessage $message,
        string $retryQueue,
        int $retryCount,
        int $delaySeconds
    ): void {
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

        $properties = $message->get_properties();
        $properties['application_headers'] = new AMQPTable(
            array_merge($this->applicationHeadersOf($message), [self::RETRY_HEADER => $retryCount]),
        );
        $properties['delivery_mode'] = 2;
        $properties['expiration'] = (string) ($delaySeconds * 1000);

        $channel->basic_publish(
            new AMQPMessage($message->getBody(), $properties),
            '',
            $retryQueue,
        );
        $channel->wait_for_pending_acks(5.0);
        $message->ack();
    }

    public function shouldRouteToDlq(AMQPMessage $message, int $maxRedeliveries): bool
    {
        return $this->retryCountFor($message) >= $maxRedeliveries;
    }

    private function retryDelaySeconds(int $retryCount): int
    {
        return self::BASE_RETRY_DELAY_SECONDS * (1 << max(0, $retryCount - 1));
    }

    private function retryCountFor(AMQPMessage $message): int
    {
        $headers = $this->applicationHeadersOf($message);

        return isset($headers[self::RETRY_HEADER]) && is_int($headers[self::RETRY_HEADER])
            ? $headers[self::RETRY_HEADER]
            : 0;
    }

    /** @return array<string, mixed> */
    private function applicationHeadersOf(AMQPMessage $message): array
    {
        if (!$message->has('application_headers')) {
            return [];
        }

        $headers = $message->get('application_headers');
        if (!$headers instanceof AMQPTable) {
            return [];
        }

        /** @var array<string, mixed> $data */
        $data = $headers->getNativeData();

        return $data;
    }
}
