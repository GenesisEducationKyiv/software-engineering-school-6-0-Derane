<?php

declare(strict_types=1);

namespace Tests\Integration\Sending\Infrastructure;

use App\Sending\Infrastructure\Rabbit\SendReleaseEmailConsumer;
use PDO;
use PhpAmqpLib\Message\AMQPMessage;
use Tests\Integration\IntegrationTestCase;

/**
 * Proves end-to-end idempotency: the same SendReleaseEmail/v1 payload
 * processed twice produces exactly one email in MailHog and one row in
 * release_notifications. No mocks — real broker, real Postgres, real MailHog.
 */
final class IdempotencyProofTest extends IntegrationTestCase
{
    private const MAILHOG_API = 'http://mailhog:8025/api/v2/messages';
    private const EXCHANGE = 'notifications';
    private const ROUTING_KEY = 'release.email';

    private SendReleaseEmailConsumer $consumer;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->consumer = $this->c->get(SendReleaseEmailConsumer::class);
        $this->purgeQueue();
    }

    public function testProcessingTheSameReleaseEmailTwiceProducesExactlyOneEmailAndOneLedgerRow(): void
    {
        $token = $this->uniqueToken();
        $payload = $this->buildPayload($token);

        $this->publish($payload);
        self::assertSame('ack', $this->pullAndProcessOne(), 'First delivery must be acked.');

        $this->publish($payload);
        self::assertSame('ack', $this->pullAndProcessOne(), 'Duplicate delivery must also be acked (dedupe-skip).');

        self::assertSame(1, $this->ledgerRowCountFor($payload), 'Exactly one ledger row.');
        self::assertCount(1, $this->mailHogMessagesContaining($token), 'Exactly one email delivered.');
    }

    public function testADifferentReleaseEmailIsDeliveredIndependently(): void
    {
        $token = $this->uniqueToken();
        $payload = $this->buildPayload($token);

        $this->publish($payload);
        self::assertSame('ack', $this->pullAndProcessOne());

        self::assertSame(1, $this->ledgerRowCountFor($payload));
        self::assertCount(1, $this->mailHogMessagesContaining($token));
    }

    /** @param array<string, mixed> $payload */
    private function publish(array $payload): void
    {
        $message = new AMQPMessage(
            json_encode($payload, JSON_THROW_ON_ERROR),
            ['content_type' => 'application/json', 'delivery_mode' => 2],
        );

        $this->rabbitChannel()->basic_publish($message, self::EXCHANGE, self::ROUTING_KEY);
    }

    /**
     * Pulls one message via basic_get (synchronous, no polling) and runs it
     * through the real consumer. Returns 'ack' if the queue is empty after
     * processing, 'nack' if the message was requeued.
     *
     * basic_get is used instead of basic_consume+wait so the test does not
     * depend on broker-side delivery timing.
     */
    private function pullAndProcessOne(): string
    {
        $channel = $this->rabbitChannel();

        $message = $channel->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: false);
        self::assertInstanceOf(
            AMQPMessage::class,
            $message,
            'Expected a message on ' . SendReleaseEmailConsumer::QUEUE . ' — publish() may not have landed.',
        );

        $this->consumer->handleDelivery($message);

        $remaining = $channel->basic_get(SendReleaseEmailConsumer::QUEUE, no_ack: true);
        if ($remaining instanceof AMQPMessage) {
            $remaining->nack(requeue: true);
            return 'nack';
        }

        return 'ack';
    }

    private function purgeQueue(): void
    {
        $this->rabbitChannel()->queue_purge(SendReleaseEmailConsumer::QUEUE);
    }

    /** @return array<string, mixed> */
    private function buildPayload(string $token): array
    {
        return [
            'schema' => 'SendReleaseEmail/v1',
            'eventId' => $this->uuid(),
            'occurredAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            'subscriptionId' => 9101,
            'email' => $token . '@example.test',
            'repository' => 'idempotency/repo-' . $token,
            'release' => [
                'tagName' => 'v0-' . $token,
                'name' => 'Idempotency Proof Release ' . $token,
                'body' => 'Idempotency proof release body for ' . $token,
                'htmlUrl' => 'https://example.test/releases/' . $token,
                'publishedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::RFC3339),
            ],
        ];
    }

    private function uniqueToken(): string
    {
        return 'e2-' . bin2hex(random_bytes(6));
    }

    private function uuid(): string
    {
        return sprintf(
            '%08s-%04s-4%03s-8%03s-%012s',
            bin2hex(random_bytes(4)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(2)),
            bin2hex(random_bytes(6)),
        );
    }

    /**
     * Counts rows by the (subscription_id, tag_name, repository) triple — uses
     * COUNT(*) rather than hasBeenSent() to catch hypothetical duplicate rows
     * that a boolean check could not distinguish from "exactly one".
     *
     * @param array<string, mixed> $payload
     */
    private function ledgerRowCountFor(array $payload): int
    {
        /** @var array{subscriptionId: int, repository: string, release: array{tagName: string}} $payload */
        $stmt = $this->c->get(PDO::class)->prepare(
            'SELECT COUNT(*) FROM release_notifications
             WHERE subscription_id = :sub AND tag_name = :tag AND repository = :repo'
        );
        $stmt->execute([
            ':sub' => $payload['subscriptionId'],
            ':tag' => $payload['release']['tagName'],
            ':repo' => $payload['repository'],
        ]);

        return (int) ($stmt->fetchColumn() ?: 0);
    }

    /**
     * Polls MailHog until a message containing $token appears or 10 s elapse.
     * Bounded polling rather than a fixed sleep so the test finishes as soon
     * as MailHog receives the message.
     *
     * @return list<string>
     */
    private function mailHogMessagesContaining(string $token): array
    {
        $deadline = microtime(true) + 10.0;
        $matches = [];

        do {
            $body = @file_get_contents(self::MAILHOG_API);
            if (is_string($body)) {
                /** @var array{items?: list<array<string, mixed>>}|null $decoded */
                $decoded = json_decode($body, true);
                $matches = $this->matchingItems($decoded, $token);
                if ($matches !== []) {
                    return $matches;
                }
            }

            usleep(200_000);
        } while (microtime(true) < $deadline);

        return $matches;
    }

    /**
     * @param array{items?: list<array<string, mixed>>}|null $decoded
     * @return list<string>
     */
    private function matchingItems(?array $decoded, string $token): array
    {
        if ($decoded === null || !isset($decoded['items']) || !is_array($decoded['items'])) {
            return [];
        }

        $matches = [];
        foreach ($decoded['items'] as $item) {
            $raw = json_encode($item, JSON_THROW_ON_ERROR);
            if (str_contains($raw, $token)) {
                $matches[] = $raw;
            }
        }

        return $matches;
    }
}
