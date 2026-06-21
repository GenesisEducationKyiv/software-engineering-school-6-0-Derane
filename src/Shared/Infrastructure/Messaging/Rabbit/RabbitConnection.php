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
 * - Queue `notifications.send-email.retry` — durable, no consumers; messages
 *   carry a per-message TTL and dead-letter back into `notifications.send-email`
 *   via the default exchange (x-dead-letter-routing-key = the work queue name)
 * - Queue `notifications.send-email.dlq` — durable
 * - Binding notifications → notifications.send-email on release.email
 * - Binding notifications.dlx → notifications.send-email.dlq (no routing key)
 *
 * Welcome-email family (HW9 saga welcome path — mirrors the send-email family;
 * the source of truth shared with the extracted notification service):
 * - Queue notifications.welcome-email — durable, x-dead-letter-exchange: notifications.dlx
 * - Queue notifications.welcome-email.retry — durable TTL park, .dlq — durable
 * - Binding notifications → notifications.welcome-email on subscription.welcome-email;
 *   notifications.dlx → notifications.welcome-email.dlq
 * - Queue notifications.welcome-email-reply — plain durable reply queue (no DLX),
 *   bound on subscription.welcome-email.reply. The hyphen is a sibling-not-child
 *   marker (arch §7) — do not "correct" it to a dot.
 *
 * @psalm-api
 */
final readonly class RabbitConnection
{
    // Public so the publisher targets the same exchange/routing key this class
    // declares and binds — one source of truth for the publish topology.
    public const EXCHANGE_NOTIFICATIONS = 'notifications';
    public const ROUTING_KEY_RELEASE_EMAIL = 'release.email';
    // Welcome family — public so the relay (Epic D) and the reply consumer target
    // the same topology this class declares and binds.
    public const ROUTING_KEY_WELCOME_EMAIL = 'subscription.welcome-email';
    public const QUEUE_WELCOME_EMAIL = 'notifications.welcome-email';
    public const QUEUE_WELCOME_EMAIL_RETRY = 'notifications.welcome-email.retry';
    public const QUEUE_WELCOME_EMAIL_DLQ = 'notifications.welcome-email.dlq';
    public const ROUTING_KEY_WELCOME_EMAIL_REPLY = 'subscription.welcome-email.reply';
    public const QUEUE_WELCOME_EMAIL_REPLY = 'notifications.welcome-email-reply';
    private const EXCHANGE_DLX = 'notifications.dlx';
    private const QUEUE_SEND_EMAIL = 'notifications.send-email';
    private const QUEUE_SEND_EMAIL_RETRY = 'notifications.send-email.retry';
    private const QUEUE_SEND_EMAIL_DLQ = 'notifications.send-email.dlq';

    public function __construct(private AMQPChannel $channel)
    {
        $this->assertTopology();
    }

    /**
     * Returns the connection's SINGLE shared channel — the same instance every
     * caller receives. A process that consumes on this channel (e.g. SagaWorker's
     * reply consumer parked in wait()) must NOT also publish with confirms on it:
     * confirm_select() flips the whole channel into publisher-confirm mode and
     * wait_for_pending_acks() interleaves confirm frames with deliveries, corrupting
     * the consume loop. Open a DEDICATED channel for confirm-mode publishing via
     * `channel()->getConnection()->channel()` instead (see RabbitWelcomeEmailRelay,
     * RabbitConsumer::republishDelayed, RabbitWelcomeOutcomePublisher — H1/M2).
     */
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
        // Fanout DLX → DLQ: no routing key has any matching semantics here —
        // the broadcast is unconditional (Decision 1).
        $this->channel->queue_bind(
            self::QUEUE_SEND_EMAIL_DLQ,
            self::EXCHANGE_DLX
        );

        $this->assertWelcomeTopology();
    }

    /**
     * Welcome-email family — same envelope as the send-email family (work queue
     * with DLX → notifications.dlx, TTL-park retry, durable DLQ) plus a plain
     * durable reply queue with no DLX of its own. Declared idempotently here so
     * the monolith side of the welcome topology matches the notification service
     * byte-for-byte (one source of truth, arch §7).
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
        // Plain durable reply queue — no DLX of its own.
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
