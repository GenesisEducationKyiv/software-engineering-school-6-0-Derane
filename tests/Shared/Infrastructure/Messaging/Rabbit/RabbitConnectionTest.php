<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

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
        $channel->expects(self::exactly(7))
            ->method('queue_declare')
            ->willReturnCallback(function (...$args) use (&$queueDeclareCalls): void {
                $queueDeclareCalls[] = $args;
            });

        $queueBindCalls = [];
        $channel->expects(self::exactly(5))
            ->method('queue_bind')
            ->willReturnCallback(function (...$args) use (&$queueBindCalls): void {
                $queueBindCalls[] = $args;
            });

        new RabbitConnection($channel);

        self::assertSame(
            ['notifications', 'topic', false, true, false],
            array_slice($exchangeDeclareCalls[0], 0, 5)
        );
        self::assertSame(
            ['notifications.dlx', 'fanout', false, true, false],
            array_slice($exchangeDeclareCalls[1], 0, 5)
        );

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

        self::assertSame('notifications.send-email.retry', $queueDeclareCalls[1][0]);
        self::assertFalse($queueDeclareCalls[1][1]);  // passive
        self::assertTrue($queueDeclareCalls[1][2]);   // durable
        self::assertFalse($queueDeclareCalls[1][3]);  // exclusive
        self::assertFalse($queueDeclareCalls[1][4]);  // auto_delete
        self::assertFalse($queueDeclareCalls[1][5]);  // nowait
        self::assertSame(
            [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', 'notifications.send-email'],
            ],
            $queueDeclareCalls[1][6]
        );

        self::assertSame('notifications.send-email.dlq', $queueDeclareCalls[2][0]);
        self::assertFalse($queueDeclareCalls[2][1]);  // passive
        self::assertTrue($queueDeclareCalls[2][2]);   // durable
        self::assertFalse($queueDeclareCalls[2][3]);  // exclusive
        self::assertFalse($queueDeclareCalls[2][4]);  // auto_delete

        self::assertSame(
            ['notifications.send-email', 'notifications', 'release.email'],
            array_slice($queueBindCalls[0], 0, 3)
        );
        self::assertSame('notifications.send-email.dlq', $queueBindCalls[1][0]);
        self::assertSame('notifications.dlx', $queueBindCalls[1][1]);
        self::assertSame('', $queueBindCalls[1][2]);  // fanout DLX: no routing-key semantics

        // Welcome-email family (HW9) — same envelope as the send-email family.
        self::assertSame('notifications.welcome-email', $queueDeclareCalls[3][0]);
        self::assertTrue($queueDeclareCalls[3][2]);   // durable
        self::assertSame(
            ['x-dead-letter-exchange' => ['S', 'notifications.dlx']],
            $queueDeclareCalls[3][6]
        );

        self::assertSame('notifications.welcome-email.retry', $queueDeclareCalls[4][0]);
        self::assertTrue($queueDeclareCalls[4][2]);   // durable
        self::assertSame(
            [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', 'notifications.welcome-email'],
            ],
            $queueDeclareCalls[4][6]
        );

        self::assertSame('notifications.welcome-email.dlq', $queueDeclareCalls[5][0]);
        self::assertTrue($queueDeclareCalls[5][2]);   // durable

        // Reply queue: hyphen sibling (not a dotted DLQ child), plain durable, no DLX.
        self::assertSame('notifications.welcome-email-reply', $queueDeclareCalls[6][0]);
        self::assertTrue($queueDeclareCalls[6][2]);   // durable
        self::assertSame([], $queueDeclareCalls[6][6]);  // empty arguments — no DLX of its own

        self::assertSame(
            ['notifications.welcome-email', 'notifications', 'subscription.welcome-email'],
            array_slice($queueBindCalls[2], 0, 3)
        );
        self::assertSame('notifications.welcome-email.dlq', $queueBindCalls[3][0]);
        self::assertSame('notifications.dlx', $queueBindCalls[3][1]);
        self::assertSame(
            ['notifications.welcome-email-reply', 'notifications', 'subscription.welcome-email.reply'],
            array_slice($queueBindCalls[4], 0, 3)
        );
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
