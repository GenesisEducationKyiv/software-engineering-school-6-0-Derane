<?php

declare(strict_types=1);

namespace App\Saga\Enrollment\Infrastructure\Rabbit;

use App\Saga\Enrollment\Domain\SendWelcomeEmail;
use App\Saga\Enrollment\Domain\WelcomeEmailRelay;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublishFailedException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * The outbox-style relay's publish adapter (FR4). Publishes a SendWelcomeEmail/v1
 * to the `notifications` exchange on `subscription.welcome-email` with publisher
 * confirms, then waits for the broker ack and throws on a nack/confirm-timeout.
 *
 * The relay runs INSIDE the SagaWorker, which consumes the reply queue on the
 * connection's single long-lived channel ({@see RabbitConnection::channel()}).
 * Publishing here therefore opens a DEDICATED confirm-mode channel on the same
 * connection — exactly the pattern {@see RabbitConsumer}::republishDelayed and the
 * notification-side RabbitWelcomeOutcomePublisher use — so the consume channel is
 * never flipped into publisher-confirm mode and its deliveries are never buffered
 * behind this confirm-wait (H1). Sharing the RabbitPublisher (which binds the shared
 * consume channel and calls confirm_select on it) would corrupt the worker's wait().
 *
 * A throw is the contract: publish() returns ONLY on a confirmed publish, so the
 * relay use-case advances the saga (markPublished) only then. On any unconfirmed
 * publish (no connection, broker nack, or confirm-timeout) a
 * RabbitPublishFailedException propagates, the use-case records the relay failure,
 * and the STARTED saga is retried next tick — the saga is NEVER advanced on an
 * unconfirmed publish.
 *
 * AMQP envelope: content_type=application/json, delivery_mode=2 (persistent),
 * correlation_id = sagaId (trace continuity, the fixed reply routing key carries
 * the reply address — no per-message reply_to, arch §7).
 *
 * @psalm-api
 */
final readonly class RabbitWelcomeEmailRelay implements WelcomeEmailRelay
{
    private const CONFIRM_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RabbitConnection $connection,
        private SendWelcomeEmailSerializer $serializer,
        private LoggerInterface $logger,
    ) {
    }

    #[\Override]
    public function publish(SendWelcomeEmail $message): void
    {
        $amqpConnection = $this->connection->channel()->getConnection();
        if ($amqpConnection === null) {
            // No live connection to publish on. Fail closed so the use-case records
            // the relay failure and leaves the saga STARTED for retry.
            throw RabbitPublishFailedException::confirmTimedOut(
                RabbitConnection::EXCHANGE_NOTIFICATIONS,
                RabbitConnection::ROUTING_KEY_WELCOME_EMAIL,
                self::CONFIRM_TIMEOUT_SECONDS,
            );
        }

        // Dedicated confirm-mode channel so the worker's consume channel is untouched.
        $publishChannel = $amqpConnection->channel();
        try {
            $publishChannel->confirm_select();
            // Register the nack handler BEFORE publishing: wait_for_pending_acks()
            // invokes it on a broker nack rather than throwing, so without it a
            // nacked message would be treated as confirmed and the saga advanced.
            $publishChannel->set_nack_handler(static function (): void {
                throw RabbitPublishFailedException::nacked(
                    RabbitConnection::EXCHANGE_NOTIFICATIONS,
                    RabbitConnection::ROUTING_KEY_WELCOME_EMAIL,
                );
            });
            $amqpMessage = new AMQPMessage($this->serializer->toJson($message), [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'correlation_id' => $message->sagaId,
            ]);
            $publishChannel->basic_publish(
                $amqpMessage,
                RabbitConnection::EXCHANGE_NOTIFICATIONS,
                RabbitConnection::ROUTING_KEY_WELCOME_EMAIL,
            );
            $publishChannel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
        } catch (AMQPTimeoutException) {
            throw RabbitPublishFailedException::confirmTimedOut(
                RabbitConnection::EXCHANGE_NOTIFICATIONS,
                RabbitConnection::ROUTING_KEY_WELCOME_EMAIL,
                self::CONFIRM_TIMEOUT_SECONDS,
            );
        } finally {
            try {
                $publishChannel->close();
            } catch (\Throwable) {
                // best-effort close; the connection may already be gone
            }
        }

        // Logged only AFTER the confirm-wait succeeds: a nack/timeout throws above
        // and nothing is logged, so a logged "published" always means confirmed.
        $this->logger->info('welcome email command published', [
            'saga_id' => $message->sagaId,
            'subscription_id' => $message->subscriptionId,
            'repository' => $message->repository->value(),
        ]);
    }
}
