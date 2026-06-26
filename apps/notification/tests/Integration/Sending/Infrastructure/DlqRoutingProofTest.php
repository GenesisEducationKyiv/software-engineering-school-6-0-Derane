<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use PDO;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Integration\IntegrationTestCase;

final class DlqRoutingProofTest extends IntegrationTestCase
{
    private const EXCHANGE = 'notifications';
    private const ROUTING_KEY = 'release.email';
    private const DLQ = 'notifications.send-email.dlq';

    private SendReleaseEmailConsumer $consumer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = $this->c->get(SendReleaseEmailConsumer::class);
        $this->purgeQueues();
    }

    public function testMalformedMessageIsDeadLetteredAndCountedWithoutTouchingTheLedger(): void
    {
        $this->rabbitChannel()->basic_publish(
            new AMQPMessage('{not valid json', ['content_type' => 'application/json', 'delivery_mode' => 2]),
            self::EXCHANGE,
            self::ROUTING_KEY,
        );

        $message = $this->rabbitChannel()->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: false);
        self::assertInstanceOf(AMQPMessage::class, $message);

        $this->consumer->handleDelivery($message);

        $deadLettered = $this->pullDeadLetter();
        self::assertInstanceOf(AMQPMessage::class, $deadLettered);
        self::assertSame('{not valid json', $deadLettered->getBody());
        self::assertSame(0, $this->ledgerRows(), 'malformed poison messages must not create ledger rows');
        self::assertSame(1, $this->metricValue('dlq_total'));
    }

    private function purgeQueues(): void
    {
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::QUEUE);
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::RETRY_QUEUE);
        $this->rabbitChannel()->queue_purge(self::DLQ);
    }

    private function pullDeadLetter(): ?AMQPMessage
    {
        $deadline = microtime(true) + 2.0;
        do {
            $message = $this->rabbitChannel()->basic_get(self::DLQ, no_ack: true);
            if ($message instanceof AMQPMessage) {
                return $message;
            }

            usleep(50_000);
        } while (microtime(true) < $deadline);

        return null;
    }

    private function ledgerRows(): int
    {
        return (int) $this->c->get(PDO::class)
            ->query('SELECT COUNT(*) FROM release_notifications')
            ->fetchColumn();
    }

    private function metricValue(string $metric): int
    {
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT metric_value FROM notification_metrics WHERE metric_name = :metric'
        );
        $stmt->execute([':metric' => $metric]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }
}
