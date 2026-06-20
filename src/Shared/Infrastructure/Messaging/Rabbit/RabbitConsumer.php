<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Exception\AMQPTimeoutException;
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
final readonly class RabbitConsumer implements MessageConsumer
{
    private const RETRY_HEADER = 'x-retry-count';

    /** First retry delay; doubles with each subsequent attempt (5s, 10s, 20s…). */
    private const BASE_RETRY_DELAY_SECONDS = 5;

    /** Per-publish broker-confirm wait for the retry/park copy. */
    private const CONFIRM_TIMEOUT_SECONDS = 5.0;

    /**
     * Bounded prefetch: the broker hands at most this many unacked messages to
     * one consumer at a time. Without it the whole queue is pushed into the
     * worker's socket buffer on startup/backlog, and each claim-contended
     * message that parks triggers a retry-queue republish — turning a burst
     * into an unbounded memory + confirm round-trip storm. A small window keeps
     * memory flat and lets multiple workers share the load fairly.
     */
    private const PREFETCH_COUNT = 10;

    public function __construct(private RabbitConnection $connection)
    {
    }

    /**
     * @param callable(AMQPMessage): void $callback
     */
    #[\Override]
    public function consume(string $queue, callable $callback): void
    {
        $channel = $this->connection->channel();
        $channel->basic_qos(0, self::PREFETCH_COUNT, false);
        $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            $callback
        );
    }

    #[\Override]
    public function ack(AMQPMessage $message): void
    {
        $message->ack();
    }

    #[\Override]
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
    #[\Override]
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
    #[\Override]
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
        $properties = $message->get_properties();
        $properties['application_headers'] = new AMQPTable(
            array_merge($this->applicationHeadersOf($message), [self::RETRY_HEADER => $retryCount]),
        );
        $properties['delivery_mode'] = 2;
        $properties['expiration'] = (string) ($delaySeconds * 1000);

        $connection = $this->connection->channel()->getConnection();
        if ($connection === null) {
            // No live connection to publish the copy on. Fail closed: do NOT ack
            // the original — it stays unacked for redelivery.
            throw RetryPublishFailedException::noConnection($retryQueue);
        }

        // Publish the delayed copy on a DEDICATED confirm-mode channel so the
        // long-lived consume channel is never flipped into publisher-confirm mode
        // nor has its deliveries buffered behind this confirm-wait.
        $publishChannel = $connection->channel();
        try {
            $publishChannel->confirm_select();
            // Register the nack handler BEFORE publishing: wait_for_pending_acks()
            // invokes it on a broker nack rather than throwing, so without it a
            // nacked copy would be treated as confirmed and the original acked —
            // dropping the notification permanently. Throwing keeps it unacked.
            $publishChannel->set_nack_handler(static function () use ($retryQueue): void {
                throw RetryPublishFailedException::brokerNacked($retryQueue);
            });
            $publishChannel->basic_publish(new AMQPMessage($message->getBody(), $properties), '', $retryQueue);
            $publishChannel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
        } catch (AMQPTimeoutException $e) {
            // Broker did not confirm in time (slow but alive — the exact case the
            // retry machinery exists to survive). Fail closed: do NOT ack the
            // original. A dedicated exception type stops the consumer poll loop
            // from swallowing this as an idle-poll timeout.
            throw RetryPublishFailedException::confirmTimedOut($retryQueue, self::CONFIRM_TIMEOUT_SECONDS, $e);
        } finally {
            try {
                $publishChannel->close();
            } catch (\Throwable) {
                // best-effort close; the connection may already be gone
            }
        }

        $message->ack();
    }

    #[\Override]
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
