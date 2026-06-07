<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * D4 cross-deployable copy of the monolith's `App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer`
 * (C4, `src/Shared/Infrastructure/Messaging/Rabbit/RabbitConsumer.php`).
 * `apps/notification` is a standalone deployable with its own composer
 * autoload root (`App\` → `apps/notification/src/`) — it cannot `use` a
 * monolith class across that boundary, exactly like D3 could not import
 * `App\Config\SmtpConfig`/`App\Scanning\...\RenderedEmail` and instead
 * recreated/promoted them. This is a deliberate, byte-for-byte copy (not a
 * drift) — keep both in sync if the shared ack/nack/x-death/DLQ contract
 * changes; a future shared-package extraction (outside this story's scope)
 * would remove the duplication structurally. `SendReleaseEmailConsumer` is
 * this copy's first real caller.
 *
 * Generic, **message-shape-agnostic** consumer scaffolding (Decision 3 —
 * operates on raw AMQP message envelopes; zero knowledge of `SendReleaseEmail`
 * or any other `Notification\*` wire shape — D4's eventual
 * `SendReleaseEmailConsumer` is the anti-corruption layer that deserializes
 * bodies and maps them to Domain VOs).
 *
 * Exposes the primitives architecture §6's `SendReleaseEmailHandler` shape
 * needs ("success → ack; transient failure → nack (bounded retry → DLQ)"):
 *
 * - {@see self::consume()} — registers a callback against a queue
 * - {@see self::ack()} / {@see self::nack()} — generic ack/nack/requeue
 * - {@see self::redeliveryCountFor()} — reads the message's `x-death` count
 *   for a specific queue
 * - {@see self::shouldRouteToDlq()} — the bounded-retry rule (AC4 / epics.md's
 *   literal AC: *"When a message exceeds N redeliveries (x-death), it routes
 *   to the DLQ instead of infinite redelivery"*)
 *
 * ## Why nack(requeue: false) routes to the DLQ, not just "discards"
 *
 * `notifications.send-email` is declared (by `RabbitConnection`, Decision 2)
 * with `x-dead-letter-exchange: notifications.dlx`. A `nack`/`reject` with
 * `requeue: false` therefore does **not** discard the message — the broker
 * dead-letters it to `notifications.dlx`, which (being a `fanout`, Decision 1)
 * unconditionally forwards it to the one bound queue,
 * `notifications.send-email.dlq`. "Bounded retry → DLQ" is thus implemented
 * entirely through topology + this nack flag — no explicit "republish to DLQ"
 * code is needed anywhere.
 *
 * ## `x-death` shape (Dev Notes — "array of records, inspect the right entry")
 *
 * When a message is dead-lettered and redelivered, RabbitMQ stamps an
 * `x-death` header: an **array of records**, one per distinct
 * `(queue, reason)` pair the message has been dead-lettered through, each
 * shaped roughly like `['queue' => ..., 'reason' => ..., 'count' => ..., ...]`.
 * {@see self::redeliveryCountFor()} sums the `count` of every record whose
 * `queue` matches the queue passed in — summing (not "first match") is
 * correct because RabbitMQ can append more than one record for the same
 * queue across distinct dead-lettering episodes with different `reason`s
 * (e.g. `rejected` then `expired`), and a caller bounding "how many times has
 * this message bounced through *this* queue in total" wants the total, not
 * an arbitrary single record's count.
 *
 * Intentionally has zero callers yet (Decision 3/seam framing — D4's
 * `SendReleaseEmailConsumer` is the first caller). `@psalm-api` documents
 * that "unused" is correct here, mirroring `ReleaseNotificationPublisher`.
 *
 * @psalm-api
 */
final readonly class RabbitConsumer
{
    public function __construct(private RabbitConnection $connection)
    {
    }

    /**
     * Registers `$callback` against `$queue` — the broker invokes it with
     * each delivered `AMQPMessage`. This is pure registration scaffolding;
     * the business-logic decision of what to do with each message (deserialize,
     * dedupe-check, render, send, ack/nack) is entirely the caller's
     * (D4's `SendReleaseEmailConsumer`), per Decision 3's anti-corruption
     * framing.
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
     * - `$requeue = true`  → the broker redelivers the message (transient-failure retry path).
     * - `$requeue = false` → the broker dead-letters the message via the queue's
     *   `x-dead-letter-exchange` (here: `notifications.dlx` → `notifications.send-email.dlq`,
     *   per the topology `RabbitConnection` asserts) — the bounded-retry-exhausted path.
     */
    public function nack(AMQPMessage $message, bool $requeue): void
    {
        $message->nack($requeue);
    }

    /**
     * Sums the `x-death` redelivery `count` across every record matching
     * `$queue` — i.e. "how many times has this message been dead-lettered
     * through *this* queue, in total, regardless of reason". Returns `0`
     * when the message carries no `x-death` header at all (first delivery).
     */
    public function redeliveryCountFor(AMQPMessage $message, string $queue): int
    {
        $total = 0;
        foreach ($this->xDeathRecords($message) as $record) {
            if ($this->xDeathRecordQueue($record) === $queue) {
                $total += $this->xDeathRecordCount($record);
            }
        }

        return $total;
    }

    /**
     * Reads a record's `queue` field defensively — `null` when absent
     * (mirrors `ReleaseFactory`'s `isset(...) ? (string) ... : default`
     * narrowing-via-cast convention for reading scalar fields out of an
     * `array<array-key, mixed>` of broker/API-stamped metadata).
     *
     * @param array<array-key, mixed> $record
     */
    private function xDeathRecordQueue(array $record): ?string
    {
        return isset($record['queue']) ? (string) $record['queue'] : null;
    }

    /**
     * Reads a record's `count` field defensively — `0` when absent.
     *
     * @param array<array-key, mixed> $record
     */
    private function xDeathRecordCount(array $record): int
    {
        return isset($record['count']) ? (int) $record['count'] : 0;
    }

    /**
     * Extracts the message's `x-death` header as a list of native-array
     * records (each record is itself a `string => mixed` map — RabbitMQ
     * stamps `queue`/`reason`/`count`/`exchange`/etc.). Returns an empty
     * list whenever the header is absent or shaped unexpectedly — a
     * malformed/missing `x-death` header is treated as "first delivery"
     * (count 0), never as an error: this scaffolding's job is to read the
     * count defensively, not to validate broker-stamped metadata.
     *
     * @return list<array<array-key, mixed>>
     */
    private function xDeathRecords(AMQPMessage $message): array
    {
        if (!$message->has('application_headers')) {
            return [];
        }

        $headers = $message->get('application_headers');
        if (!$headers instanceof AMQPTable) {
            return [];
        }

        /** @var array<string, mixed> $headerData */
        $headerData = $headers->getNativeData();

        $xDeath = $headerData['x-death'] ?? null;
        if (!is_array($xDeath)) {
            return [];
        }

        // AMQPTable::getNativeData() returns array<string, mixed>; after the
        // is_array guard above we know $xDeath is an array, but its element
        // type is still mixed (third-party untyped boundary). We assert the
        // element type so psalm can infer through the foreach body at 100%
        // coverage. Non-array elements at runtime safely contribute 0:
        // they pass through to xDeathRecordQueue()/xDeathRecordCount(), where
        // the isset(...) guards return false on non-array values, so no match
        // is found and the count added is 0 — identical to the absent-field path.
        /** @var list<array<array-key, mixed>> $xDeath */
        $records = [];
        foreach ($xDeath as $value) {
            $records[] = $value;
        }

        return $records;
    }

    /**
     * The bounded-retry rule (AC4): once `$message`'s `x-death` count for
     * `$queue` **exceeds** `$maxRedeliveries`, the caller should
     * `nack($message, requeue: false)` to route it to the DLQ instead of
     * redelivering it forever.
     *
     * "Exceeds" (strictly greater than), not "reaches", per epics.md's
     * literal "exceeds N redeliveries" wording — a message is allowed exactly
     * `$maxRedeliveries` redelivery attempts before this returns `true`.
     */
    public function shouldRouteToDlq(AMQPMessage $message, string $queue, int $maxRedeliveries): bool
    {
        return $this->redeliveryCountFor($message, $queue) > $maxRedeliveries;
    }
}
