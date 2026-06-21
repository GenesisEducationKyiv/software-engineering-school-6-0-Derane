<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use PhpAmqpLib\Channel\AMQPChannel;
use PHPUnit\Framework\TestCase;

/**
 * Proves the service-side topology matches the monolith byte-for-byte (one
 * source of truth, arch §7): the send-email family plus the HW9 welcome-email
 * family with its hyphen reply queue.
 */
final class RabbitConnectionTest extends TestCase
{
    public function testAssertsTheFullTopologyIncludingTheWelcomeFamily(): void
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

        // Send-email family.
        self::assertSame('notifications.send-email', $queueDeclareCalls[0][0]);
        self::assertSame('notifications.send-email.retry', $queueDeclareCalls[1][0]);
        self::assertSame('notifications.send-email.dlq', $queueDeclareCalls[2][0]);

        // Welcome-email family.
        self::assertSame('notifications.welcome-email', $queueDeclareCalls[3][0]);
        self::assertTrue($queueDeclareCalls[3][2]);   // durable
        self::assertSame(
            ['x-dead-letter-exchange' => ['S', 'notifications.dlx']],
            $queueDeclareCalls[3][6]
        );

        self::assertSame('notifications.welcome-email.retry', $queueDeclareCalls[4][0]);
        self::assertSame(
            [
                'x-dead-letter-exchange' => ['S', ''],
                'x-dead-letter-routing-key' => ['S', 'notifications.welcome-email'],
            ],
            $queueDeclareCalls[4][6]
        );

        self::assertSame('notifications.welcome-email.dlq', $queueDeclareCalls[5][0]);

        // Reply queue: hyphen sibling, plain durable, no DLX of its own.
        self::assertSame('notifications.welcome-email-reply', $queueDeclareCalls[6][0]);
        self::assertTrue($queueDeclareCalls[6][2]);   // durable
        self::assertSame([], $queueDeclareCalls[6][6]);  // empty arguments — no DLX

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

    public function testExposesTheUnderlyingChannel(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        $connection = new RabbitConnection($channel);

        self::assertSame($channel, $connection->channel());
    }
}
