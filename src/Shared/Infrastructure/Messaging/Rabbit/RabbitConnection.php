<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Asserts the full broker topology idempotently on construction:
 * - Exchange `notifications` — topic, durable
 * - Exchange `notifications.dlx` — fanout, durable (fanout routes dead-lettered
 *   messages unconditionally without a routing key — simpler than topic for a
 *   single DLQ)
 * - Queue `notifications.send-email` — durable, x-dead-letter-exchange: notifications.dlx
 * - Queue `notifications.send-email.dlq` — durable
 * - Binding notifications → notifications.send-email on release.email
 * - Binding notifications.dlx → notifications.send-email.dlq (no routing key)
 *
 * @psalm-api
 */
final readonly class RabbitConnection
{
    private const EXCHANGE_NOTIFICATIONS = 'notifications';
    private const EXCHANGE_DLX = 'notifications.dlx';
    private const QUEUE_SEND_EMAIL = 'notifications.send-email';
    private const QUEUE_SEND_EMAIL_DLQ = 'notifications.send-email.dlq';
    private const ROUTING_KEY_RELEASE_EMAIL = 'release.email';

    public function __construct(private AMQPChannel $channel)
    {
        $this->assertTopology();
    }

    public function channel(): AMQPChannel
    {
        return $this->channel;
    }

    private function assertTopology(): void
    {
        // --- Exchanges -------------------------------------------------
        $this->channel->exchange_declare(
            self::EXCHANGE_NOTIFICATIONS,
            'topic',
            false,
            true,   // durable
            false   // auto_delete
        );
        $this->channel->exchange_declare(
            self::EXCHANGE_DLX,
            'fanout',
            false,
            true,   // durable
            false   // auto_delete
        );

        // --- Queues -----------------------------------------------------
        $this->channel->queue_declare(
            self::QUEUE_SEND_EMAIL,
            false,
            true,   // durable
            false,  // exclusive
            false,  // auto_delete
            false,  // nowait
            ['x-dead-letter-exchange' => ['S', self::EXCHANGE_DLX]]
        );
        $this->channel->queue_declare(
            self::QUEUE_SEND_EMAIL_DLQ,
            false,
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );

        // --- Bindings ----------------------------------------------------
        $this->channel->queue_bind(
            self::QUEUE_SEND_EMAIL,
            self::EXCHANGE_NOTIFICATIONS,
            self::ROUTING_KEY_RELEASE_EMAIL
        );
        // Fanout DLX → DLQ: no routing key has any matching semantics here —
        // the broadcast is unconditional (Decision 1).
        $this->channel->queue_bind(
            self::QUEUE_SEND_EMAIL_DLQ,
            self::EXCHANGE_DLX
        );
    }
}
