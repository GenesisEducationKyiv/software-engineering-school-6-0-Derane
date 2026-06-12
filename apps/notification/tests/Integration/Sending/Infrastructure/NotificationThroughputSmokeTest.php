<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use PDO;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Integration\IntegrationTestCase;

final class NotificationThroughputSmokeTest extends IntegrationTestCase
{
    private const MAILHOG_API = 'http://mailhog:8025/api/v2/messages';
    private const MAILHOG_DELETE = 'http://mailhog:8025/api/v1/messages';
    private const EXCHANGE = 'notifications';
    private const ROUTING_KEY = 'release.email';
    private const BATCH_SIZE = 5;

    private SendReleaseEmailConsumer $consumer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = $this->c->get(SendReleaseEmailConsumer::class);
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::QUEUE);
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::RETRY_QUEUE);
        @file_get_contents(self::MAILHOG_DELETE, false, $this->deleteContext());
    }

    public function testBatchOfReleaseEmailsIsProcessedEndToEndWithinBoundedPollBudget(): void
    {
        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $this->publish($i);
        }

        for ($i = 1; $i <= self::BATCH_SIZE; $i++) {
            $message = $this->rabbitChannel()->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: false);
            self::assertInstanceOf(AMQPMessage::class, $message);

            $this->consumer->handleDelivery($message);
        }

        self::assertSame(self::BATCH_SIZE, $this->ledgerRows());
        self::assertSame(self::BATCH_SIZE, $this->metricValue('consumed_total'));
        self::assertSame(self::BATCH_SIZE, $this->metricValue('delivered_total'));
        self::assertSame(self::BATCH_SIZE, $this->mailHogTotal());
    }

    private function publish(int $index): void
    {
        $payload = [
            'schema' => 'SendReleaseEmail/v1',
            'eventId' => sprintf('11111111-1111-4111-8111-%012d', $index),
            'occurredAt' => '2026-06-07T12:00:00+00:00',
            'subscriptionId' => 9200 + $index,
            'email' => sprintf('load-smoke-%d@example.test', $index),
            'repository' => 'load/smoke',
            'release' => [
                'tagName' => sprintf('v1.0.%d', $index),
                'name' => sprintf('Load Smoke %d', $index),
                'body' => 'Notification load smoke body.',
                'htmlUrl' => sprintf('https://example.test/releases/%d', $index),
                'publishedAt' => '2026-06-07T11:00:00+00:00',
            ],
        ];

        $this->rabbitChannel()->basic_publish(
            new AMQPMessage(json_encode($payload, JSON_THROW_ON_ERROR), [
                'content_type' => 'application/json',
                'delivery_mode' => 2,
            ]),
            self::EXCHANGE,
            self::ROUTING_KEY,
        );
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

    private function mailHogTotal(): int
    {
        $deadline = microtime(true) + 10.0;
        do {
            $body = @file_get_contents(self::MAILHOG_API);
            if (is_string($body)) {
                /** @var array{total?: int}|null $decoded */
                $decoded = json_decode($body, true);
                $total = (int) ($decoded['total'] ?? 0);
                if ($total >= self::BATCH_SIZE) {
                    return $total;
                }
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        return 0;
    }

    /** @return resource */
    private function deleteContext()
    {
        return stream_context_create(['http' => ['method' => 'DELETE']]);
    }
}
