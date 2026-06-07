<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Application\SendReleaseEmailHandler;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * The anti-corruption layer between the `notifications.send-email` queue and
 * `SendReleaseEmailHandler`: deserializes message bodies, invokes the
 * handler, and translates its outcome into ack/nack/DLQ per `RabbitConsumer`'s
 * bounded-retry contract (FR7/FR9).
 *
 * ## The malformed-vs-transient branching design (Technical Decisions §1)
 *
 * `handleDelivery()` runs **two separate, sequential** try/catch blocks —
 * deliberately never merged or nested:
 *
 * 1. Deserialize the body via {@see SendReleaseEmailMessageMapper::fromJson()}.
 *    A {@see MalformedReleaseEmailMessageException} here means the message is
 *    *fundamentally unprocessable* — undecodable JSON, or well-formed JSON
 *    missing/wrong-typing a required field. Redelivering it changes nothing;
 *    it will fail identically every time. It goes `nack(requeue: false)`
 *    **straight to the DLQ on first sighting** — `shouldRouteToDlq()` is
 *    NEVER consulted for this path. Consulting it would let a poison message
 *    burn through `MAX_REDELIVERIES` redeliveries before reaching the DLQ —
 *    wasted broker churn on a message that was never going to succeed, and a
 *    violation of "rejected straight to the DLQ" for malformed messages.
 *
 * 2. Invoke `$handler->handle($releaseEmail)` on a *well-formed* message. A
 *    `\Throwable` here (e.g. `Mailer::send()`'s SMTP exception, propagating
 *    uncaught through D3's no-try/catch handler) means the failure is
 *    *environmental* — the message might succeed on a later attempt. We
 *    delegate the bound-check entirely to `RabbitConsumer::shouldRouteToDlq()`
 *    (never reimplementing `x-death` counting here): below the bound →
 *    `nack(requeue: true)` (redeliver, bounded retry continues); bound
 *    exceeded → `nack(requeue: false)` (DLQ).
 *
 * Catching `\Throwable` (not `\Exception`) around the handler invocation
 * mirrors this consumer's job as the worker's outermost safety net — nothing
 * the handler/its three ports do should be able to crash the long-lived
 * consume loop; every failure mode must resolve to an ack or a nack.
 *
 * On success (including the handler's internal idempotent-skip — its
 * `hasBeenSent` short-circuit is invisible here: "returned without throwing"
 * is the only signal this consumer needs, and it always means ack), the
 * message is acked — the broker permanently removes it.
 */
final readonly class SendReleaseEmailConsumer
{
    public const QUEUE = 'notifications.send-email';

    /**
     * Bounded-retry ceiling passed to `RabbitConsumer::shouldRouteToDlq()`.
     * A message is allowed exactly this many redelivery attempts (i.e.
     * `MAX_REDELIVERIES + 1` total delivery attempts) before being routed to
     * the DLQ — see the class-level docblock and the Dev Agent Record for the
     * chosen value's rationale.
     */
    public const MAX_REDELIVERIES = 3;

    public function __construct(
        private RabbitConsumer $consumer,
        private SendReleaseEmailHandler $handler,
        private SendReleaseEmailMessageMapper $mapper,
    ) {
    }

    /**
     * Registers {@see self::handleDelivery()} as the `notifications.send-email`
     * queue's delivery callback — the entry point `bin/consumer.php` calls to
     * begin consuming.
     */
    public function start(): void
    {
        $this->consumer->consume(self::QUEUE, $this->handleDelivery(...));
    }

    public function handleDelivery(AMQPMessage $message): void
    {
        try {
            $releaseEmail = $this->mapper->fromJson($message->getBody());
        } catch (MalformedReleaseEmailMessageException) {
            // Poison message — fundamentally unprocessable. Straight to the
            // DLQ on first sighting; no bound check, ever (see class docblock).
            $this->consumer->nack($message, requeue: false);
            return;
        }

        try {
            $this->handler->handle($releaseEmail);
            $this->consumer->ack($message);
        } catch (\Throwable) {
            // Transient failure on a well-formed message — bounded retry via
            // the broker's x-death count, delegated to RabbitConsumer.
            if ($this->consumer->shouldRouteToDlq($message, self::QUEUE, self::MAX_REDELIVERIES)) {
                $this->consumer->nack($message, requeue: false);
            } else {
                $this->consumer->nack($message, requeue: true);
            }
        }
    }
}
