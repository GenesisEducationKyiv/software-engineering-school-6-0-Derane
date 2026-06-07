<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Generic, **message-shape-agnostic** publisher (Decision 3 — takes a raw
 * body string + routing metadata, never a domain type such as
 * `SendReleaseEmail`; the eventual `RabbitReleaseNotificationPublisher` (C5)
 * is responsible for serializing its domain message to JSON *before* calling
 * this class).
 *
 * Uses **publisher confirms** (AC3 / epics.md's literal AC: *"it uses
 * publisher confirms and only returns success on broker ack; on negative
 * confirm/timeout it raises"*):
 *
 * ## Confirm-mode lifecycle (Decision 6 — `confirm_select` once per channel)
 *
 * `confirm_select()` is a **channel-level** setting — calling it repeatedly
 * is harmless but wasteful and signals a misunderstanding of the API. This
 * class therefore calls it exactly **once**, in its constructor, and never
 * again inside `publish()`'s hot path. It also registers `set_ack_handler`/
 * `set_nack_handler` once — both are invoked synchronously by
 * `wait_for_pending_acks()` while it processes the broker's `basic.ack`/
 * `basic.nack` confirm frames for the message just published.
 *
 * ## Chosen v3 API surface (documented per the Dev Notes' "get the exact
 * method names right" warning — verified against the installed
 * `vendor/php-amqplib/php-amqplib` v3.7.4 `AMQPChannel` source)
 *
 * 1. constructor: `confirm_select()`, `set_ack_handler(...)`, `set_nack_handler(...)`
 * 2. `publish()`: `basic_publish($message, $exchange, $routingKey)`,
 *    then `wait_for_pending_acks($timeoutSeconds)`
 *
 * `wait_for_pending_acks` blocks until the broker resolves every
 * `published_messages` entry (i.e. until our ack/nack handler fires for the
 * message we just published) or the timeout elapses — at which point it
 * throws `AMQPTimeoutException`. Combined with the ack/nack flag this class
 * tracks per `publish()` call, that gives the exact three outcomes AC3
 * requires:
 *
 * - **ack** → `publish()` returns normally
 * - **nack** → `publish()` throws {@see RabbitPublishFailedException::nacked()}
 * - **timeout** → `publish()` throws {@see RabbitPublishFailedException::confirmTimedOut()}
 *
 * Intentionally has zero callers yet (Decision 3/seam framing — C5's
 * `RabbitReleaseNotificationPublisher` is the first caller). `@psalm-api`
 * documents that "unused" is correct here, mirroring `ReleaseNotificationPublisher`.
 *
 * @psalm-api
 */
final readonly class RabbitPublisher
{
    private const DEFAULT_CONFIRM_TIMEOUT_SECONDS = 5.0;

    private AMQPChannel $channel;
    private float $confirmTimeoutSeconds;

    /**
     * Tracks broker-acked messages across the channel's lifetime so the
     * ack/nack handlers (registered once, in the constructor) can report
     * back to whichever `publish()` call is currently waiting — see the
     * mutability note above the constructor.
     *
     * @var \SplObjectStorage<AMQPMessage, true>
     */
    private \SplObjectStorage $ackedMessages;

    /**
     * Same shape and purpose as {@see self::$ackedMessages}, for nacks.
     *
     * @var \SplObjectStorage<AMQPMessage, true>
     */
    private \SplObjectStorage $nackedMessages;

    /**
     * `confirm_select`/`set_ack_handler`/`set_nack_handler` all happen here —
     * exactly once per channel (Decision 6). The two `\SplObjectStorage`
     * instances are mutated by the ack/nack handler closures as confirms
     * arrive; they are themselves `readonly`-held *references* (never
     * reassigned), so the class stays `final readonly class` — only the
     * objects' internal contents change, the same pattern `AggregateRoot`'s
     * event buffer uses (a `readonly`-compatible mutable collection held by
     * reference).
     */
    public function __construct(RabbitConnection $connection, ?float $confirmTimeoutSeconds = null)
    {
        $this->channel = $connection->channel();
        $this->confirmTimeoutSeconds = $confirmTimeoutSeconds ?? self::DEFAULT_CONFIRM_TIMEOUT_SECONDS;
        $this->ackedMessages = new \SplObjectStorage();
        $this->nackedMessages = new \SplObjectStorage();

        $this->channel->confirm_select();
        $this->channel->set_ack_handler(function (AMQPMessage $message): void {
            $this->ackedMessages->attach($message);
        });
        $this->channel->set_nack_handler(function (AMQPMessage $message): void {
            $this->nackedMessages->attach($message);
        });
    }

    /**
     * Publishes `$body` to `$exchange` addressed by `$routingKey`, returning
     * normally **only** when the broker positively confirms (`basic.ack`)
     * the message. `$properties` are passed straight through to
     * `AMQPMessage` (e.g. `content_type`, `delivery_mode`, `message_id`) —
     * this class neither inspects nor requires any of them.
     *
     * @param array<string, mixed> $properties AMQP message properties
     *        (e.g. ['delivery_mode' => 2, 'content_type' => 'application/json'])
     *
     * @throws RabbitPublishFailedException on broker nack or confirm-wait timeout
     */
    public function publish(string $exchange, string $routingKey, string $body, array $properties = []): void
    {
        $message = new AMQPMessage($body, $properties);

        $this->channel->basic_publish($message, $exchange, $routingKey);

        try {
            $this->channel->wait_for_pending_acks($this->confirmTimeoutSeconds);
        } catch (AMQPTimeoutException) {
            throw RabbitPublishFailedException::confirmTimedOut($exchange, $routingKey, $this->confirmTimeoutSeconds);
        }

        $nacked = $this->nackedMessages->contains($message);
        $this->ackedMessages->detach($message);
        $this->nackedMessages->detach($message);

        if ($nacked) {
            throw RabbitPublishFailedException::nacked($exchange, $routingKey);
        }
    }
}
