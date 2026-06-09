<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * Wraps a `php-amqplib` channel and idempotently asserts the full broker
 * topology on construction. Topology declared once here so publishers and
 * consumers never call `exchange_declare`/`queue_declare`/`queue_bind`
 * themselves — they inject this and call `channel()`.
 *
 * Topology:
 * - Exchange `notifications` — topic, durable
 * - Exchange `notifications.dlx` — fanout, durable (fanout so dead-lettered
 *   messages route to the DLQ without needing a matching routing key)
 * - Queue `notifications.send-email` — durable, x-dead-letter-exchange: notifications.dlx
 * - Queue `notifications.send-email.dlq` — durable
 * - Binding `notifications` → `notifications.send-email` on key `release.email`
 * - Binding `notifications.dlx` → `notifications.send-email.dlq`
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

        $this->channel->queue_bind(
            self::QUEUE_SEND_EMAIL,
            self::EXCHANGE_NOTIFICATIONS,
            self::ROUTING_KEY_RELEASE_EMAIL
        );
        $this->channel->queue_bind(
            self::QUEUE_SEND_EMAIL_DLQ,
            self::EXCHANGE_DLX
        );
    }
}
