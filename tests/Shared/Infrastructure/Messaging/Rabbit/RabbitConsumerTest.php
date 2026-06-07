<?php

declare(strict_types=1);

namespace Tests\Shared\Infrastructure\Messaging\Rabbit;

use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConnection;
use App\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;

/**
 * AC4: `RabbitConsumer` exposes generic ack/nack/requeue primitives and
 * routes over-redelivered messages to the DLQ via bounded `x-death`
 * inspection — proven against fixture `AMQPMessage`s with synthetic
 * `x-death` headers (no live broker).
 */
final class RabbitConsumerTest extends TestCase
{
    private const QUEUE = 'notifications.send-email';

    public function testRegistersTheCallbackAgainstTheGivenQueue(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $callback = static function (AMQPMessage $message): void {
        };

        $channel->expects(self::once())
            ->method('basic_consume')
            ->with(self::QUEUE, '', false, false, false, false, $callback);

        $consumer = new RabbitConsumer($this->connectionWrapping($channel));
        $consumer->consume(self::QUEUE, $callback);
    }

    public function testAckDelegatesToTheMessagesOwnAcknowledgement(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects(self::once())->method('basic_ack')->with(7, false);

        $message = $this->deliveredMessage($channel, deliveryTag: 7);

        (new RabbitConsumer($this->connectionWrapping($channel)))->ack($message);
    }

    public function testNackWithRequeueTrueAsksTheBrokerToRedeliver(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects(self::once())->method('basic_nack')->with(11, false, true);

        $message = $this->deliveredMessage($channel, deliveryTag: 11);

        (new RabbitConsumer($this->connectionWrapping($channel)))->nack($message, requeue: true);
    }

    public function testNackWithRequeueFalseRoutesToTheDlqViaTheQueuesDeadLetterExchange(): void
    {
        $channel = $this->createMock(AMQPChannel::class);
        $channel->expects(self::once())->method('basic_nack')->with(13, false, false);

        $message = $this->deliveredMessage($channel, deliveryTag: 13);

        (new RabbitConsumer($this->connectionWrapping($channel)))->nack($message, requeue: false);
    }

    public function testRedeliveryCountIsZeroWhenTheMessageCarriesNoXDeathHeader(): void
    {
        $consumer = new RabbitConsumer($this->connectionWrapping($this->createMock(AMQPChannel::class)));

        $freshMessage = new AMQPMessage('{"v":1}');

        self::assertSame(0, $consumer->redeliveryCountFor($freshMessage, self::QUEUE));
        self::assertFalse($consumer->shouldRouteToDlq($freshMessage, self::QUEUE, maxRedeliveries: 3));
    }

    public function testRedeliveryCountReadsOnlyTheRecordMatchingTheTargetQueueAmongMultipleRecords(): void
    {
        $consumer = new RabbitConsumer($this->connectionWrapping($this->createMock(AMQPChannel::class)));

        // Realistic fixture: multiple x-death records, only one (and a second,
        // different-reason one) matching notifications.send-email — proving
        // we sum the matching records and ignore the unrelated queue's record.
        $message = $this->messageWithXDeath([
            ['queue' => 'some-other-queue', 'reason' => 'expired', 'count' => 9],
            ['queue' => self::QUEUE, 'reason' => 'rejected', 'count' => 2],
            ['queue' => self::QUEUE, 'reason' => 'expired', 'count' => 1],
        ]);

        self::assertSame(3, $consumer->redeliveryCountFor($message, self::QUEUE));
    }

    /** @return array<array{0: int, 1: int, 2: bool}> [redeliveryCount, maxRedeliveries, expectedShouldRouteToDlq] */
    public static function dlqRoutingBoundaryProvider(): array
    {
        return [
            'below the bound -> retry/requeue path' => [2, 3, false],
            'at the bound -> still within allowance, retry/requeue path' => [3, 3, false],
            'one over the bound -> DLQ-routing path' => [4, 3, true],
            'far over the bound -> DLQ-routing path' => [10, 3, true],
        ];
    }

    /**
     * @dataProvider dlqRoutingBoundaryProvider
     */
    public function testShouldRouteToDlqFiresOnlyWhenTheRedeliveryBoundIsExceeded(
        int $redeliveryCount,
        int $maxRedeliveries,
        bool $expectedShouldRouteToDlq
    ): void {
        $consumer = new RabbitConsumer($this->connectionWrapping($this->createMock(AMQPChannel::class)));

        $message = $this->messageWithXDeath([
            ['queue' => self::QUEUE, 'reason' => 'rejected', 'count' => $redeliveryCount],
        ]);

        self::assertSame(
            $expectedShouldRouteToDlq,
            $consumer->shouldRouteToDlq($message, self::QUEUE, $maxRedeliveries)
        );
    }

    /**
     * @param array<int, array{queue: string, reason: string, count: int}> $records
     */
    private function messageWithXDeath(array $records): AMQPMessage
    {
        return new AMQPMessage('{"v":1}', [
            'application_headers' => new AMQPTable(['x-death' => $records]),
        ]);
    }

    private function deliveredMessage(AMQPChannel $channel, int $deliveryTag): AMQPMessage
    {
        $message = new AMQPMessage('{"v":1}');
        $message->setChannel($channel)->setDeliveryInfo($deliveryTag, false, 'notifications', 'release.email');

        return $message;
    }

    private function connectionWrapping(AMQPChannel $channel): RabbitConnection
    {
        $channel->method('exchange_declare')->willReturn(null);
        $channel->method('queue_declare')->willReturn(null);
        $channel->method('queue_bind')->willReturn(null);

        return new RabbitConnection($channel);
    }
}
