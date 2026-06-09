<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * ## Why nack(requeue: false) routes to the DLQ, not just "discards"
 *
 * `notifications.send-email` is declared with
 * `x-dead-letter-exchange: notifications.dlx`. A `nack`/`reject` with
 * `requeue: false` therefore does **not** discard the message — the broker
 * dead-letters it to `notifications.dlx`, which (being a `fanout`)
 * unconditionally forwards it to `notifications.send-email.dlq`.
 * "Bounded retry → DLQ" is implemented entirely through topology + this nack
 * flag — no explicit "republish to DLQ" code is needed anywhere.
 *
 * ## `x-death` shape (array of records, inspect the right entry)
 *
 * When a message is dead-lettered, RabbitMQ stamps an `x-death` header:
 * an **array of records**, one per distinct `(queue, reason)` pair, each
 * shaped like `['queue' => ..., 'reason' => ..., 'count' => ..., ...]`.
 * {@see self::redeliveryCountFor()} sums `count` across every matching-queue
 * record — summing (not "first match") because RabbitMQ can append multiple
 * records for the same queue with different reasons (e.g. `rejected` then
 * `expired`).
 *
 * @psalm-api
 */
final readonly class RabbitConsumer
{
    public function __construct(private RabbitConnection $connection)
    {
    }

    /** @param callable(AMQPMessage): void $callback */
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

    /** @param array<array-key, mixed> $record */
    private function xDeathRecordQueue(array $record): ?string
    {
        return isset($record['queue']) ? (string) $record['queue'] : null;
    }

    /** @param array<array-key, mixed> $record */
    private function xDeathRecordCount(array $record): int
    {
        return isset($record['count']) ? (int) $record['count'] : 0;
    }

    /** @return list<array<array-key, mixed>> */
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

    // Uses > not >=: a message gets exactly $maxRedeliveries retry attempts before routing to DLQ.
    public function shouldRouteToDlq(AMQPMessage $message, string $queue, int $maxRedeliveries): bool
    {
        return $this->redeliveryCountFor($message, $queue) > $maxRedeliveries;
    }
}
