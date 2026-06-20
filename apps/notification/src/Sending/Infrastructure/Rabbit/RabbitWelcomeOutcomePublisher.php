<?php

declare(strict_types=1);

namespace App\Sending\Infrastructure\Rabbit;

use App\Sending\Domain\WelcomeOutcome;
use App\Sending\Domain\WelcomeOutcomePublisher;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use Psr\Log\LoggerInterface;

/**
 * The notification service's first publisher: emits a `WelcomeEmailOutcome/v1`
 * reply to the `notifications` exchange on `subscription.welcome-email.reply`.
 *
 * Fails closed with publisher confirms, mirroring
 * {@see \App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer}::republishDelayed:
 * a dedicated confirm-mode channel (so the long-lived consume channel is never
 * flipped into confirm mode), a nack handler registered BEFORE publishing, and a
 * bounded confirm-wait. Any unconfirmed publish (no connection, broker nack, or
 * timeout) throws {@see WelcomeOutcomePublishFailedException} — the caller must
 * NOT proceed as if the reply was sent.
 */
final readonly class RabbitWelcomeOutcomePublisher implements WelcomeOutcomePublisher
{
    private const CONFIRM_TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private RabbitConnection $connection,
        private LoggerInterface $logger,
        private WelcomeOutcomeSerializer $serializer = new WelcomeOutcomeSerializer(),
    ) {
    }

    #[\Override]
    public function publish(
        string $sagaId,
        int $subscriptionId,
        WelcomeOutcome $outcome,
        ?string $error = null,
    ): void {
        $body = $this->serializer->toJson(
            $sagaId,
            $subscriptionId,
            $outcome,
            $error,
            new \DateTimeImmutable(),
        );

        $amqpConnection = $this->connection->channel()->getConnection();
        if ($amqpConnection === null) {
            // No live connection to publish on. Fail closed.
            throw WelcomeOutcomePublishFailedException::noConnection();
        }

        // Dedicated confirm-mode channel so the consume channel is untouched.
        $publishChannel = $amqpConnection->channel();
        try {
            $publishChannel->confirm_select();
            $publishChannel->set_nack_handler(static function (): void {
                throw WelcomeOutcomePublishFailedException::brokerNacked();
            });
            $message = new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
                'correlation_id' => $sagaId,
            ]);
            $publishChannel->basic_publish(
                $message,
                RabbitConnection::EXCHANGE_NOTIFICATIONS_PUBLIC,
                RabbitConnection::ROUTING_KEY_WELCOME_EMAIL_REPLY,
            );
            $publishChannel->wait_for_pending_acks(self::CONFIRM_TIMEOUT_SECONDS);
        } catch (AMQPTimeoutException $e) {
            throw WelcomeOutcomePublishFailedException::confirmTimedOut(self::CONFIRM_TIMEOUT_SECONDS, $e);
        } finally {
            try {
                $publishChannel->close();
            } catch (\Throwable) {
                // best-effort close; the connection may already be gone
            }
        }

        $this->logger->info('WelcomeEmailOutcome reply published', [
            'saga_id' => $sagaId,
            'subscription_id' => $subscriptionId,
            'outcome' => $outcome->value,
        ]);
    }
}
