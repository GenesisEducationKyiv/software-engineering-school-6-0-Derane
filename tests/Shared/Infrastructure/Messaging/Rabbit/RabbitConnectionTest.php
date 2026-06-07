<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

/**
 * AC2: `RabbitConnection` idempotently asserts the exact AR-MQ1 topology
 * (exchange/queue/binding/DLX/DLQ — verbatim from C3's handoff) on
 * construction, via a mocked `AMQPChannel` — no live broker required.
 */
final class RabbitConnectionTest extends TestCase
{
    public function testAssertsTheExactTopologyOnConstruction(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $exchangeDeclareCalls = [];
        $channel->expects(self::exactly(2))
            ->method('exchange_declare')
            ->willReturnCallback(function (...$args) use (&$exchangeDeclareCalls): void {
                $exchangeDeclareCalls[] = $args;
            });

        $queueDeclareCalls = [];
        $channel->expects(self::exactly(2))
            ->method('queue_declare')
            ->willReturnCallback(function (...$args) use (&$queueDeclareCalls): void {
                $queueDeclareCalls[] = $args;
            });

        $queueBindCalls = [];
        $channel->expects(self::exactly(2))
            ->method('queue_bind')
            ->willReturnCallback(function (...$args) use (&$queueBindCalls): void {
                $queueBindCalls[] = $args;
            });

        new RabbitConnection($channel);

        // --- Exchanges: notifications (topic, durable) then notifications.dlx (fanout, durable) ---
        self::assertSame(
            ['notifications', 'topic', false, true, false],
            array_slice($exchangeDeclareCalls[0], 0, 5)
        );
        self::assertSame(
            ['notifications.dlx', 'fanout', false, true, false],
            array_slice($exchangeDeclareCalls[1], 0, 5)
        );

        // --- Queues: notifications.send-email (durable, DLX arg) then the DLQ (durable) ---
        self::assertSame('notifications.send-email', $queueDeclareCalls[0][0]);
        self::assertFalse($queueDeclareCalls[0][1]);  // passive
        self::assertTrue($queueDeclareCalls[0][2]);   // durable
        self::assertFalse($queueDeclareCalls[0][3]);  // exclusive
        self::assertFalse($queueDeclareCalls[0][4]);  // auto_delete
        self::assertFalse($queueDeclareCalls[0][5]);  // nowait
        self::assertSame(
            ['x-dead-letter-exchange' => ['S', 'notifications.dlx']],
            $queueDeclareCalls[0][6]
        );

        self::assertSame('notifications.send-email.dlq', $queueDeclareCalls[1][0]);
        self::assertFalse($queueDeclareCalls[1][1]);  // passive
        self::assertTrue($queueDeclareCalls[1][2]);   // durable
        self::assertFalse($queueDeclareCalls[1][3]);  // exclusive
        self::assertFalse($queueDeclareCalls[1][4]);  // auto_delete

        // --- Bindings: notifications -> send-email (release.email), dlx -> dlq (fanout, no key) ---
        self::assertSame(
            ['notifications.send-email', 'notifications', 'release.email'],
            array_slice($queueBindCalls[0], 0, 3)
        );
        self::assertSame('notifications.send-email.dlq', $queueBindCalls[1][0]);
        self::assertSame('notifications.dlx', $queueBindCalls[1][1]);
        self::assertSame('', $queueBindCalls[1][2]);  // fanout DLX -> DLQ: no routing-key semantics (Decision 1)
    }

    public function testExposesTheUnderlyingChannelForReuseByPublisherAndConsumer(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        $connection = new RabbitConnection($channel);

        self::assertSame($channel, $connection->channel());
    }
}
