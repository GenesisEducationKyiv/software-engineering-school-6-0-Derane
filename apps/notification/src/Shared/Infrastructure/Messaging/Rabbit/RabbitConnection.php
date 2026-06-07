<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;

/**
 * D4 cross-deployable copy of the monolith's `App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection`
 * (C4, `src/Shared/Infrastructure/Messaging/Rabbit/RabbitConnection.php`).
 * `apps/notification` is a standalone deployable with its own composer
 * autoload root (`App\` → `apps/notification/src/`) — it cannot `use` a
 * monolith class across that boundary, exactly like D3 could not import
 * `App\Config\SmtpConfig`/`App\Scanning\...\RenderedEmail` and instead
 * recreated/promoted them. This is a deliberate, byte-for-byte copy (not a
 * drift) — keep both in sync if the shared topology/DLQ contract changes;
 * a future shared-package extraction (outside this story's scope) would
 * remove the duplication structurally.
 *
 * Wraps a `php-amqplib` channel and idempotently asserts the full AR-MQ1
 * broker topology on construction (Decision 2 — C3's handoff names this
 * class as the topology owner).
 *
 * ## Construction shape (AC2/AC6 — "constructed from the rabbitmq settings
 * group", eager-vs-lazy is "the dev's call")
 *
 * This class itself takes an already-open `AMQPChannel` (one channel per
 * connection — the `php-amqplib` idiom) rather than the raw settings tuple.
 * The settings-driven part — building the `AMQPStreamConnection` from
 * `host`/`port`/`user`/`password`/`vhost` and opening its channel — lives in
 * the `config/container.php` factory closure (mirroring the existing
 * `PDO::class`/`RedisClient::class` precedent: the factory reads `$settings`
 * and constructs the underlying connection object). That keeps the
 * *topology-asserting* responsibility (this class — pure `php-amqplib`
 * channel calls, fully mockable) cleanly separate from the
 * *socket-opening* responsibility (the DI factory — which necessarily opens
 * a real TCP connection and therefore cannot run, even partially, inside a
 * Unit-suite test with no live broker). AC2's literal requirement — "asserts
 * the full topology on first connect" — is satisfied either way; this split
 * is what makes AC2's mocked-channel unit test possible at all without a
 * live broker.
 *
 * Connection establishment is, in effect, **eager**: by the time a
 * `RabbitConnection` exists, its channel is open and its topology is
 * asserted — there is no further lazy step. `$vhost` reaches
 * `AMQPStreamConnection` (in the DI factory) via that constructor's
 * dedicated scalar argument — never assembled into a connection URI string —
 * which is what sidesteps the `RABBITMQ_VHOST=/` URL-encoding pitfall C3's
 * Dev Agent Record flagged (`AMQPStreamConnection::__construct(string $host,
 * int $port, string $user, string $password, string $vhost)` accepts the raw
 * `/` directly; encoding only matters if you build-and-parse an
 * `amqp://user:pass@host:port/vhost` URI, which nothing here does).
 *
 * ## `final readonly class` (AC7 — no documented exception needed)
 *
 * Every property is assigned exactly once in the constructor and never
 * reassigned afterwards — there is no lazy memoization, unlike
 * `SafeGitHubCacheDecorator`'s mutable failure-logging flags. `readonly`
 * therefore applies cleanly; this class needs **no** documented deviation
 * from the convention (the underlying `AMQPChannel` object is itself
 * mutable third-party state, but *this wrapper's reference to it* never
 * changes, which is what `readonly` guards).
 *
 * ## Topology asserted here (verbatim from C3's Decision-2 handoff / AR-MQ1)
 *
 * - Exchange `notifications` — `topic`, durable
 * - Exchange `notifications.dlx` — `fanout` (Decision 1: the simplest correct
 *   choice for routing dead-lettered messages to the single DLQ — a fanout
 *   broadcasts unconditionally to every bound queue, so no routing-key
 *   matching is needed; the dead-lettering reason/origin already lives in
 *   the message's `x-death` header, which is what `RabbitConsumer` inspects,
 *   not the exchange's routing), durable
 * - Queue `notifications.send-email` — durable,
 *   `x-dead-letter-exchange: notifications.dlx`
 * - Queue `notifications.send-email.dlq` — durable
 * - Binding `notifications` → `notifications.send-email` on routing key
 *   `release.email`
 * - Binding `notifications.dlx` → `notifications.send-email.dlq` (fanout —
 *   no routing key)
 *
 * Every `*_declare`/`*_bind` call below uses `php-amqplib`'s idempotent
 * semantics: the broker no-ops when the entity already exists with matching
 * attributes, so it is safe to run this on every connect (no
 * `PRECONDITION_FAILED`, as long as nobody redeclares these names with
 * different attributes elsewhere — which is exactly the duplication C4
 * exists to prevent, per Decision 2).
 *
 * Declaring the topology here — once, in the shared connection bootstrap —
 * means C5's publisher and D4's consumer never call `exchange_declare`/
 * `queue_declare`/`queue_bind` themselves; they simply inject an
 * already-topology-asserted `RabbitConnection` and use its channel.
 *
 * Intentionally has zero callers yet — exactly like C1's `ReleaseNotificationPublisher`
 * port, this is a "seam" class that C5 (publisher adapter) and D4 (consumer)
 * will inject once they land. `@psalm-api` documents that "unused" here is
 * correct, not an oversight (mirrors the same annotation on
 * `ReleaseNotificationPublisher`/`InProcessNullReleaseNotificationPublisher`).
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

    /**
     * Exposes the single shared channel to `RabbitPublisher`/`RabbitConsumer`
     * — both reuse it rather than opening their own (one connection, one
     * channel, one already-asserted topology, per Decision 2's "declare once"
     * framing).
     */
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
