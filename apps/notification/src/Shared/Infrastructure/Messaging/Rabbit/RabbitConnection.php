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
 * - Queue `notifications.send-email.retry` — durable, no consumers; messages
 *   carry a per-message TTL and dead-letter back into `notifications.send-email`
 *   via the default exchange (x-dead-letter-routing-key = the work queue name),
 *   giving retries a broker-enforced backoff delay
 * - Queue `notifications.send-email.dlq` — durable
 * - Binding `notifications` → `notifications.send-email` on key `release.email`
 * - Binding `notifications.dlx` → `notifications.send-email.dlq`
 *
 * Welcome-email family (HW9 saga welcome path — mirrors the send-email family):
 * - Queue `notifications.welcome-email` — durable, x-dead-letter-exchange: notifications.dlx
 * - Queue `notifications.welcome-email.retry` — durable TTL park (dead-letters
 *   back into the work queue), and `.dlq` — durable
 * - Binding `notifications` → `notifications.welcome-email` on key
 *   `subscription.welcome-email`; `notifications.dlx` → `notifications.welcome-email.dlq`
 * - Queue `notifications.welcome-email-reply` — plain durable reply queue (no DLX
 *   of its own), bound on `subscription.welcome-email.reply`. The hyphen is a
 *   sibling-not-child marker (see arch §7): it is NOT a DLQ/retry child of the
 *   work queue, so it deliberately breaks the dotted-suffix convention. Do not
 *   "correct" it to a dot.
 *
 * @psalm-api
 */
final readonly class RabbitConnection
{
    // Public so the welcome relay/publisher target the same exchange/routing keys
    // this class declares and binds — one source of truth for the welcome topology
    // shared across the monolith relay (Epic D) and this service's publisher.
    public const EXCHANGE_NOTIFICATIONS_PUBLIC = 'notifications';
    public const ROUTING_KEY_WELCOME_EMAIL = 'subscription.welcome-email';
    public const QUEUE_WELCOME_EMAIL = 'notifications.welcome-email';
    public const QUEUE_WELCOME_EMAIL_RETRY = 'notifications.welcome-email.retry';
    public const QUEUE_WELCOME_EMAIL_DLQ = 'notifications.welcome-email.dlq';
    public const ROUTING_KEY_WELCOME_EMAIL_REPLY = 'subscription.welcome-email.reply';
    public const QUEUE_WELCOME_EMAIL_REPLY = 'notifications.welcome-email-reply';

    private const EXCHANGE_NOTIFICATIONS = 'notifications';
    private const EXCHANGE_DLX = 'notifications.dlx';
    private const QUEUE_SEND_EMAIL = 'notifications.send-email';
    private const QUEUE_SEND_EMAIL_RETRY = 'notifications.send-email.retry';
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
        // Retry parking queue: no consumers; expired messages dead-letter back
        // into the work queue via the default exchange (routing key = queue name).
        $this->channel->queue_declare(
            self::QUEUE_SEND_EMAIL_RETRY,
            false,
            true,   // durable
            false,  // exclusive
            false,  // auto_delete
            false,  // nowait
            [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', self::QUEUE_SEND_EMAIL],
            ]
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

        $this->assertWelcomeTopology();
    }

    /**
     * Welcome-email family — same envelope as the send-email family (work queue
     * with DLX → notifications.dlx, TTL-park retry, durable DLQ) plus a plain
     * durable reply queue with no DLX of its own.
     */
    private function assertWelcomeTopology(): void
    {
        $this->channel->queue_declare(
            self::QUEUE_WELCOME_EMAIL,
            false,
            true,   // durable
            false,  // exclusive
            false,  // auto_delete
            false,  // nowait
            ['x-dead-letter-exchange' => ['S', self::EXCHANGE_DLX]]
        );
        // Retry parking queue: no consumers; expired messages dead-letter back
        // into the welcome work queue via the default exchange (routing key = queue name).
        $this->channel->queue_declare(
            self::QUEUE_WELCOME_EMAIL_RETRY,
            false,
            true,   // durable
            false,  // exclusive
            false,  // auto_delete
            false,  // nowait
            [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', self::QUEUE_WELCOME_EMAIL],
            ]
        );
        $this->channel->queue_declare(
            self::QUEUE_WELCOME_EMAIL_DLQ,
            false,
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );
        // Plain durable reply queue — no DLX of its own (a malformed reply is
        // ack-dropped by the monolith consumer; the saga sweeper is the backstop).
        $this->channel->queue_declare(
            self::QUEUE_WELCOME_EMAIL_REPLY,
            false,
            true,   // durable
            false,  // exclusive
            false   // auto_delete
        );

        $this->channel->queue_bind(
            self::QUEUE_WELCOME_EMAIL,
            self::EXCHANGE_NOTIFICATIONS,
            self::ROUTING_KEY_WELCOME_EMAIL
        );
        $this->channel->queue_bind(
            self::QUEUE_WELCOME_EMAIL_DLQ,
            self::EXCHANGE_DLX
        );
        $this->channel->queue_bind(
            self::QUEUE_WELCOME_EMAIL_REPLY,
            self::EXCHANGE_NOTIFICATIONS,
            self::ROUTING_KEY_WELCOME_EMAIL_REPLY
        );
    }
}
