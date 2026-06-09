<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublishFailedException;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitPublisher;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\TestCase;

/**
 * `RabbitPublisher` puts the channel into confirm mode before any publish,
 * returns normally only on a broker ack, and raises
 * {@see RabbitPublishFailedException} on a broker nack or confirm-wait
 * timeout — proven against a mocked `AMQPChannel` (no live broker).
 */
final class RabbitPublisherTest extends TestCase
{
    public function testEnablesConfirmModeAndRegistersAckNackHandlersOnConstruction(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->expects(self::once())->method('confirm_select');
        $channel->expects(self::once())->method('set_ack_handler');
        $channel->expects(self::once())->method('set_nack_handler');

        new RabbitPublisher($this->connectionWrapping($channel));
    }

    public function testReturnsNormallyWhenTheBrokerAcksTheMessage(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $ackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(function (callable $cb) use (&$ackHandler): void {
            $ackHandler = $cb;
        });
        $channel->method('set_nack_handler')->willReturnCallback(static fn () => null);

        $publishedMessage = null;
        $channel->expects(self::once())
            ->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $message) use (&$publishedMessage): void {
                $publishedMessage = $message;
            });

        // Simulate the broker resolving the pending confirm with an ack —
        // wait_for_pending_acks invokes our registered ack handler synchronously.
        $channel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(function () use (&$ackHandler, &$publishedMessage): void {
                self::assertNotNull($ackHandler);
                self::assertNotNull($publishedMessage);
                ($ackHandler)($publishedMessage);
            });

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $publisher->publish('notifications', 'release.email', '{"v":1}');

        self::assertNotNull($publishedMessage);
        self::assertSame('{"v":1}', $publishedMessage->getBody());
    }

    public function testRaisesRabbitPublishFailedExceptionWhenTheBrokerNacksTheMessage(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $nackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(static fn () => null);
        $channel->method('set_nack_handler')->willReturnCallback(function (callable $cb) use (&$nackHandler): void {
            $nackHandler = $cb;
        });

        $publishedMessage = null;
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message) use (&$publishedMessage): void {
                $publishedMessage = $message;
            }
        );

        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$nackHandler, &$publishedMessage): void {
                ($nackHandler)($publishedMessage);
            }
        );

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $this->expectException(RabbitPublishFailedException::class);
        $this->expectExceptionMessage('negatively acknowledged (nack)');

        $publisher->publish('notifications', 'release.email', '{"v":1}');
    }

    public function testPublishBatchPublishesEveryBodyButWaitsForConfirmsExactlyOnce(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $ackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(function (callable $cb) use (&$ackHandler): void {
            $ackHandler = $cb;
        });
        $channel->method('set_nack_handler')->willReturnCallback(static fn () => null);

        /** @var list<AMQPMessage> $publishedMessages */
        $publishedMessages = [];
        $channel->expects(self::exactly(3))
            ->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $message) use (&$publishedMessages): void {
                $publishedMessages[] = $message;
            });

        // The whole point of the batch API: ONE confirm round trip for N messages.
        $channel->expects(self::once())
            ->method('wait_for_pending_acks')
            ->willReturnCallback(function () use (&$ackHandler, &$publishedMessages): void {
                self::assertNotNull($ackHandler);
                foreach ($publishedMessages as $message) {
                    ($ackHandler)($message);
                }
            });

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $publisher->publishBatch('notifications', 'release.email', ['{"v":1}', '{"v":2}', '{"v":3}']);

        self::assertSame(
            ['{"v":1}', '{"v":2}', '{"v":3}'],
            array_map(static fn (AMQPMessage $m): string => $m->getBody(), $publishedMessages),
        );
    }

    public function testPublishBatchRaisesRabbitPublishFailedExceptionWhenAnyMessageIsNacked(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $ackHandler = null;
        $nackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(function (callable $cb) use (&$ackHandler): void {
            $ackHandler = $cb;
        });
        $channel->method('set_nack_handler')->willReturnCallback(function (callable $cb) use (&$nackHandler): void {
            $nackHandler = $cb;
        });

        /** @var list<AMQPMessage> $publishedMessages */
        $publishedMessages = [];
        $channel->method('basic_publish')->willReturnCallback(
            function (AMQPMessage $message) use (&$publishedMessages): void {
                $publishedMessages[] = $message;
            }
        );

        // Broker acks the first and third but nacks the second — the batch must fail.
        $channel->method('wait_for_pending_acks')->willReturnCallback(
            function () use (&$ackHandler, &$nackHandler, &$publishedMessages): void {
                ($ackHandler)($publishedMessages[0]);
                ($nackHandler)($publishedMessages[1]);
                ($ackHandler)($publishedMessages[2]);
            }
        );

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $this->expectException(RabbitPublishFailedException::class);
        $this->expectExceptionMessage('negatively acknowledged (nack)');

        $publisher->publishBatch('notifications', 'release.email', ['{"v":1}', '{"v":2}', '{"v":3}']);
    }

    public function testPublishBatchChunksLargeBatchesSoEachConfirmWaitCoversABoundedSlice(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $ackHandler = null;
        $channel->method('set_ack_handler')->willReturnCallback(function (callable $cb) use (&$ackHandler): void {
            $ackHandler = $cb;
        });
        $channel->method('set_nack_handler')->willReturnCallback(static fn () => null);

        /** @var list<AMQPMessage> $pending */
        $pending = [];
        $channel->expects(self::exactly(1001))
            ->method('basic_publish')
            ->willReturnCallback(function (AMQPMessage $message) use (&$pending): void {
                $pending[] = $message;
            });

        // 1001 bodies at a 500-message chunk size → exactly 3 confirm waits.
        $channel->expects(self::exactly(3))
            ->method('wait_for_pending_acks')
            ->willReturnCallback(function () use (&$ackHandler, &$pending): void {
                self::assertNotNull($ackHandler);
                foreach ($pending as $message) {
                    ($ackHandler)($message);
                }
                $pending = [];
            });

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $publisher->publishBatch(
            'notifications',
            'release.email',
            array_map(static fn (int $i): string => "{\"v\":{$i}}", range(1, 1001)),
        );
    }

    public function testPublishBatchWithNoBodiesIsANoOp(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->method('set_ack_handler')->willReturnCallback(static fn () => null);
        $channel->method('set_nack_handler')->willReturnCallback(static fn () => null);
        $channel->expects(self::never())->method('basic_publish');
        $channel->expects(self::never())->method('wait_for_pending_acks');

        $publisher = new RabbitPublisher($this->connectionWrapping($channel));

        $publisher->publishBatch('notifications', 'release.email', []);
    }

    public function testRaisesRabbitPublishFailedExceptionOnConfirmWaitTimeout(): void
    {
        $channel = $this->createMock(AMQPChannel::class);

        $channel->method('set_ack_handler')->willReturnCallback(static fn () => null);
        $channel->method('set_nack_handler')->willReturnCallback(static fn () => null);
        $channel->method('basic_publish')->willReturnCallback(static fn () => null);
        $channel->method('wait_for_pending_acks')->willThrowException(new AMQPTimeoutException('timed out'));

        $publisher = new RabbitPublisher($this->connectionWrapping($channel), 2.5);

        $this->expectException(RabbitPublishFailedException::class);
        $this->expectExceptionMessage('Timed out after 2.5s');

        $publisher->publish('notifications', 'release.email', '{"v":1}');
    }

    /**
     * Builds a `RabbitConnection` wrapping `$channel` without exercising the
     * full topology assertion's exact arguments (covered by
     * `RabbitConnectionTest`) — here it just needs to hand `$channel` back
     * via `channel()`.
     */
    private function connectionWrapping(AMQPChannel $channel): RabbitConnection
    {
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        return new RabbitConnection($channel);
    }
}
